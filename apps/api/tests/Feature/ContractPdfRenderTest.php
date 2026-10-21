<?php

namespace Tests\Feature;

use App\Support\Reservations\ContractPdf;
use Tests\TestCase;

class ContractPdfRenderTest extends TestCase
{
    public function test_real_pdf_engine_renders_french_and_arabic_without_external_content(): void
    {
        if (! config('rental_contracts.chrome')) {
            $this->markTestSkipped('Set PDF_CHROME_BINARY to run the real PDF integration test.');
        }
        $s = ['language' => 'fr', 'agency' => ['name' => 'Agence fictive Tanger'], 'reservation' => ['id' => 999, 'starts_at' => '2026-11-12 10:00', 'ends_at' => '2026-11-15 10:00', 'total_centimes' => 270000], 'customer' => ['first_name' => 'Client fictif', 'last_name' => 'Exemple', 'phone' => '+212600000000', 'identity_type' => 'cin', 'identity_number' => 'TEST123', 'licence_number' => 'LIC123', 'licence_expires_on' => '2030-01-01'], 'vehicle' => ['make' => 'Dacia', 'model' => 'Logan', 'plate' => 'TEST-999'], 'caution' => [['action' => 'caution_set', 'amount_centimes' => 300000]], 'inspection' => ['id' => 999, 'kilometers' => 15020, 'fuel_eighths' => 6, 'condition' => 'dommages_existants', 'notes' => 'Rayure ancienne sur le pare-chocs arrière.', 'photos' => [['id' => 1]]]];
        foreach (['fr', 'ar'] as $language) {
            $s['language'] = $language;
            if ($language === 'ar') {
                $s['customer']['first_name'] = 'زبون تجريبي';
                $s['customer']['last_name'] = 'للاختبار';
                $s['inspection']['notes'] = 'خدش قديم فالجهة الخلفية، موثق بالصور قبل التسليم.';
            }
            $pdf = app(ContractPdf::class)->render($s, 'CTR-000999-V1');
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertGreaterThan(10000, strlen($pdf));
            if ($output = getenv('PDF_QA_OUTPUT')) {
                if (! is_dir($output)) {
                    mkdir($output, 0700, true);
                }file_put_contents($output.'/contract-'.$language.'.pdf', $pdf);
            }
        }
    }
}
