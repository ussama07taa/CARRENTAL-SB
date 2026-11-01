<?php
namespace App\Support\Customers;

use Symfony\Component\Process\Process;

class DocumentOcr
{
    public function read(string $bytes, string $kind): array
    {
        abort_unless(config('ocr.enabled'), 503, 'La lecture automatique est indisponible. La saisie manuelle reste possible.');
        $lock = fopen(storage_path('framework/document-ocr.lock'), 'c');
        abort_unless($lock, 503, 'Lecture indisponible.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            abort(429, 'Une lecture est déjà en cours. Réessayez dans un instant.');
        }
        try {
            $process = new Process([config('ocr.python'), config('ocr.engine'), 'auto', $kind]);
            $process->setInput($bytes)->setTimeout(150);
            $process->run();
            if (!$process->isSuccessful()) {
                abort(503, 'Lecture impossible. Essayez une photo plus nette ou la saisie manuelle.');
            }
            $result = json_decode($process->getOutput(), true);
            abort_unless(is_array($result['fields'] ?? null), 503, 'Réponse de lecture indisponible.');
            $fields = [];
            $allowed = ['first_name', 'last_name', 'birth_date', 'issue_date', 'expiry_date', $kind === 'cin' ? 'cin_number' : 'licence_number'];
            foreach ($allowed as $key) {
                $value = $result['fields'][$key]['value'] ?? null;
                if (is_string($value) && mb_strlen($value) <= 100) $fields[$key] = $value;
            }
            return $fields;
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) {
            abort(503, 'Lecture indisponible. Réessayez ou saisissez les informations manuellement.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
