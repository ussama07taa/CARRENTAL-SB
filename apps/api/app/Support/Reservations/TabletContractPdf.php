<?php

namespace App\Support\Reservations;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class TabletContractPdf
{
    /** Preserve issued page content; L4 has reserved, fixed signature rectangles. */
    public function render(string $original, array $signatures, string $reference, string $originalHash, string $signedAt): string
    {
        $directory = storage_path('app/private/tablet-render/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        try {
            $source = $directory.'/original.pdf';
            file_put_contents($source, $original);
            $info = new Process(['pdfinfo', $source]);
            $info->setTimeout(8)->mustRun();
            abort_unless(preg_match('/^Pages:\s+2\s*$/m', $info->getOutput()), 422, 'Le test tablette nécessite un contrat A4 recto-verso de deux pages.');
            $text = new Process(['pdftotext', '-layout', $source, '-']);
            $text->setTimeout(8)->mustRun();
            $inPlace = str_contains($text->getOutput(), 'MOSWADA · L4') || str_contains($text->getOutput(), 'MOSWADA v4');
            $pages = [];
            for ($page = 1; $page <= 2; $page++) {
                $output = $directory.'/page-'.$page.'.svg';
                $process = new Process(['pdftocairo', '-svg', '-f', (string) $page, '-l', (string) $page, $source, $output]);
                $process->setTimeout(10)->mustRun();
                abort_unless(is_file($output) && filesize($output) < 8 * 1024 * 1024, 422, 'Cette page dépasse la limite de prévisualisation.');
                $pages[] = 'data:image/svg+xml;base64,'.base64_encode(file_get_contents($output));
            }
            $stamp = 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(resource_path('views/contracts/sample-agency-stamp.svg')));
            $html = view($inPlace ? 'contracts.tablet-in-place' : 'contracts.tablet-signatures', compact('pages', 'signatures', 'reference', 'originalHash', 'signedAt', 'stamp'))->render();

            return app(ContractPdf::class)->renderHtml($html);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
