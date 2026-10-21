<?php

namespace App\Support\Reservations;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\Process\Process;

class ContractPdf
{
    public function html(array $snapshot, string $reference): string
    {
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $view = $snapshot;
        if (isset($snapshot['presentation'])) {
            $presentation = $snapshot['presentation'];
            $ar = ($snapshot['language'] ?? 'fr') === 'ar';
            $fuelLabels = $ar ? ['diesel' => 'الغازوال', 'petrol' => 'البنزين', 'hybrid' => 'هجين', 'electric' => 'كهربائي'] : ['diesel' => 'Diesel', 'petrol' => 'Essence', 'hybrid' => 'Hybride', 'electric' => 'Électrique'];
            $methodLabels = $ar ? ['especes' => 'نقداً', 'virement' => 'تحويل بنكي'] : ['especes' => 'Espèces', 'virement' => 'Virement'];
            $view['vehicle']['fuel'] = $fuelLabels[$presentation['fuel'] ?? ''] ?? null;
            $methods = array_map(fn (string $method): string => $methodLabels[$method] ?? $method, $presentation['verified_payment_methods'] ?? []);
            $view['payment_methods_label'] = $methods ? implode(' / ', $methods) : null;
            $view['financial'] = ['verified_centimes' => $presentation['verified_centimes'], 'remaining_centimes' => $presentation['remaining_centimes']];
            if ($presentation['agreed_daily_centimes'] !== null) {
                $view['agreed_daily_centimes'] = $presentation['agreed_daily_centimes'];
            }
            if ($presentation['first_daily_centimes'] !== null && $presentation['last_daily_centimes'] !== null) {
                $money = fn (int $value): string => number_format(intdiv($value, 100), 0, ',', ' ').','.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT).' DH';
                $view['agreed_first_last_label'] = $money($presentation['first_daily_centimes']).' / '.$money($presentation['last_daily_centimes']);
            }
        }
        $entries = collect($snapshot['caution'] ?? []);
        if (! isset($view['caution_summary'])) {
            $verified = 0;
            foreach ($entries as $entry) {
                $action = data_get($entry, 'action');
                if ($action === 'caution_received' && data_get($entry, 'method') !== 'virement') {
                    $verified += (int) data_get($entry, 'amount_centimes', 0);
                }
                if ($action === 'caution_verified') {
                    $receipt = $entries->firstWhere('id', data_get($entry, 'receipt_id'));
                    $verified += (int) data_get($receipt, 'amount_centimes', 0);
                }
            }
            $view['caution_summary'] = ['verified_centimes' => $verified - (int) ($snapshot['caution_used_centimes'] ?? 0)];
        }

        return view('contracts.rental', ['s' => $view, 'reference' => $reference, 'font' => is_file($font) ? base64_encode(file_get_contents($font)) : null])->render();
    }

    public function render(array $snapshot, string $reference): string
    {
        $pdf = $this->renderHtml($this->html($snapshot, $reference));
        if (($snapshot['presentation']['layout'] ?? null) === 4) {
            $this->assertSignatureSpace($pdf, ($snapshot['language'] ?? 'fr') === 'ar');
        }

        return $pdf;
    }

    private function assertSignatureSpace(string $pdf, bool $arabic): void
    {
        $directory = storage_path('app/private/contract-layout/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        try {
            file_put_contents($directory.'/original.pdf', $pdf);
            $process = new Process(['pdftotext', '-bbox', $directory.'/original.pdf', '-']);
            $process->setTimeout(8)->mustRun();
            $document = new \DOMDocument;
            $document->loadXML($process->getOutput(), LIBXML_NONET);
            abort_unless($document->getElementsByTagName('page')->length === 2, 422, 'Le contenu dépasse deux pages. Préparez une annexe lisible avant l’émission.');
            foreach ($document->getElementsByTagName('word') as $word) {
                $top = (float) $word->getAttribute('yMin') * 25.4 / 72;
                $height = (float) $word->getAttribute('yMax') - (float) $word->getAttribute('yMin');
                $overlap = ($top >= 259 && $top < 282) || ($top >= 250 && $top < 259 && $height > ($arabic ? 10 : 7.2));
                abort_if($overlap, 422, 'Le contenu empiète sur les signatures. Préparez une annexe lisible avant l’émission.');
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function renderHtml(string $html): string
    {
        $browser = Browsershot::html($html)->setNodeBinary(config('rental_contracts.node'))->setNpmBinary(config('rental_contracts.npm'))->setNodeModulePath(base_path('../../node_modules'))->format('A4')->margins(0, 0, 0, 0)->showBackground()->disableJavascript()->blockUrls(['http://', 'https://'])->timeout(40)->setOption('args', ['--disable-dev-shm-usage']);
        if (config('rental_contracts.chrome')) {
            $browser->setChromePath(config('rental_contracts.chrome'));
        }
        if (config('rental_contracts.no_sandbox')) {
            $browser->noSandbox();
        }
        $bytes = $browser->pdf();
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new \RuntimeException('Invalid PDF renderer output.');
        }

        return $bytes;
    }
}
