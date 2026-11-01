<?php
namespace App\Http\Controllers;

use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BackupStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $result = ['configured' => false, 'automatic' => false, 'offsite' => false, 'history' => [], 'can_create' => app(\App\Support\BackupOperation::class)->enabled(), 'operation' => app(\App\Support\BackupOperation::class)->status()];
        if ((int) config('backups.company_id') !== Tenant::id()) {
            return response()->json($result);
        }
        $root = realpath(config('backups.directory') ?: '/nonexistent-backup-directory');
        if (! $root || ! is_dir($root) || ! is_readable($root)) {
            return response()->json($result);
        }
        $result['configured'] = true;
        $directories = glob($root.'/*', GLOB_ONLYDIR) ?: [];
        rsort($directories, SORT_STRING);
        foreach ($directories as $directory) {
            $stamp = basename($directory);
            if (! preg_match('/^\d{8}T\d{12}Z$/', $stamp) || is_link($directory)) {
                continue;
            }
            $manifest = $this->read($directory.'/manifest.json');
            if (($manifest['source_database'] ?? null) !== DB::connection()->getDatabaseName()) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Ymd\THisu\Z', $stamp, new \DateTimeZone('UTC'));
            if (! $date || $date->format('Ymd\THisu\Z') !== $stamp) {
                continue;
            }
            $verification = $this->read($directory.'/verification.json');
            $complete = true;
            foreach (['database.dump', 'documents.tar.gz', 'application.tar.gz', 'environment.private', 'archive-checksums.json'] as $name) {
                if (! is_file($directory.'/'.$name) || is_link($directory.'/'.$name)) {
                    $complete = false;
                }
            }
            $verified = $complete && ($verification['verified'] ?? false) === true && ($verification['restored_database'] ?? null) === $manifest['source_database'];
            $result['history'][] = ['id' => $stamp, 'created_at' => $date->format(DATE_ATOM), 'status' => $verified ? 'verified' : 'unverified', 'files' => $verified ? (int) ($verification['files'] ?? 0) : null, 'rows' => $verified ? (int) ($verification['rows'] ?? 0) : null];
            if (count($result['history']) >= 10) {
                break;
            }
        }
        return response()->json($result);
    }

    public function create(\Illuminate\Http\Request $request): JsonResponse
    {
        return response()->json(['operation' => app(\App\Support\BackupOperation::class)->start($request->user()->id)], 202);
    }

    public function download(\Illuminate\Http\Request $request, string $backup)
    {
        abort_unless((int) config('backups.company_id') === Tenant::id(), 404);
        $input = $request->validate(['password' => ['required', 'string', 'min:12', 'max:128', 'confirmed']]);
        $root = realpath(config('backups.directory') ?: '/nonexistent');
        abort_unless($root && preg_match('/^\d{8}T\d{12}Z$/', $backup), 404);
        $directory = $root.'/'.$backup;
        abort_if(is_link($directory) || ! is_dir($directory), 404);
        $manifest = $this->read($directory.'/manifest.json');
        abort_unless(($manifest['source_database'] ?? null) === DB::connection()->getDatabaseName(), 404);
        $verification = $this->read($directory.'/verification.json');
        abort_unless(($verification['verified'] ?? false) === true && ($verification['restored_database'] ?? null) === $manifest['source_database'], 409, 'Cette sauvegarde n’a pas été validée.');
        abort_unless(\ZipArchive::isEncryptionMethodSupported(\ZipArchive::EM_AES_256), 503, 'Le chiffrement ZIP est indisponible.');
        $files = ['database.dump','documents.tar.gz','application.tar.gz','environment.private','manifest.json','archive-checksums.json','verification.json'];
        $checksums = $this->read($directory.'/archive-checksums.json');
        foreach ($files as $file) {
            $path = $directory.'/'.$file;
            abort_unless(is_file($path) && ! is_link($path) && is_readable($path), 409, 'La sauvegarde est incomplète.');
            if (! in_array($file, ['archive-checksums.json','verification.json'], true)) {
                abort_unless(isset($checksums[$file]) && is_string($checksums[$file]) && hash_equals($checksums[$file], hash_file('sha256', $path)), 409, 'La vérification de la sauvegarde a échoué.');
            }
        }
        $temporary = tempnam(sys_get_temp_dir(), 'backup-encrypted-');
        chmod($temporary, 0600);
        $zip = new \ZipArchive;
        try {
            if ($zip->open($temporary, \ZipArchive::OVERWRITE) !== true || ! $zip->setPassword($input['password'])) {
                throw new \RuntimeException('ZIP unavailable');
            }
            foreach ($files as $file) {
                if (! $zip->addFile($directory.'/'.$file, $file) || ! $zip->setEncryptionName($file, \ZipArchive::EM_AES_256)) {
                    throw new \RuntimeException('ZIP encryption failed');
                }
            }
            if (! $zip->close()) {
                throw new \RuntimeException('ZIP could not be completed');
            }
            // Append-only private receipt. Never record the password or request payload.
            $receipt = $root.'/download-'.\Illuminate\Support\Str::uuid().'.json';
            $handle = fopen($receipt, 'x');
            fwrite($handle, json_encode(['backup' => $backup, 'actor_user_id' => $request->user()->id, 'company_id' => Tenant::id(), 'prepared_at' => now()->toIso8601String()]));
            fclose($handle);chmod($receipt, 0600);
        } catch (\Throwable $e) {
            unset($zip);@unlink($temporary);
            abort(503, 'Impossible de préparer la copie chiffrée. Réessayez.');
        }
        return response()->download($temporary, 'sauvegarde-'.$backup.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    private function read(string $path): array
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }
}
