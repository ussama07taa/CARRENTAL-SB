<?php

namespace App\Support\Reservations;

use Illuminate\Support\Str;
use ZipArchive;

class ForeignAuthorization
{
    public function document(array $s, string $reference): array
    {
        $c = $s['context']['customer'];
        $v = $s['context']['vehicle'];
        $t = $s['travel'];
        $i = $s['insurance'];
        $ar = ($c['language'] ?? 'fr') === 'ar';
        $blank = $ar ? '[للاستكمال]' : '[À compléter]';
        $name = trim($c['first_name'].' '.$c['last_name']);
        $countries = implode('، ', array_merge($t['countries'], $t['transit_countries']));
        $date = fn (string $x) => str_replace('T', ' ', substr($x, 0, 16));
        $rows = $ar ? [
            ['bensbehicarrental SARL', 'B.'],
            ['ترخيص خروج سيارة من التراب المغربي', $reference],
            ['مسودة تجريبية - غير صالحة للسفر الفعلي. معلومات الوكالة والتأمين والتوقيع النهائي خاصها مراجعة.', ''],
            ['الوكالة', 'bensbehicarrental SARL · Rue Tarblous 2, n° 16, Barnes 2, Tanger · +212 661704161'],
            ['الممثل', 'Yasser Bensbehi · مسير؛ RC / ICE / IF: '.$blank],
            ['صاحب الترخيص', $name.' · '.($c['identity_number'] ?: $blank).' · '.($c['licence_number'] ?: $blank)],
            ['العنوان', $c['address'] ?: $blank],
            ['السيارة', $v['make'].' '.$v['model'].' · '.$v['plate']],
            ['رقم الهيكل', $v['vin'] ?: $blank],
            ['البلدان المقصودة وبلدان العبور', $countries],
            ['مدة الترخيص', $date($t['starts_local']).' ← '.$date($t['ends_local']).' · توقيت المغرب'],
            ['التأمين الدولي', $i['insurer'].' · '.$i['insurance_reference']],
            ['صلاحية شهادة التأمين', $i['insurance_starts_on'].' ← '.$i['insurance_expires_on']],
            ['نطاق المسودة', 'موافقة داخلية مسجلة على البلدان والمدة أعلاه فقط. لا تعوض شهادة التأمين أو إجراءات الحدود. يلزم التحقق من صفة صاحب التوقيع ووثائق السيارة والضمانات لدى شركة التأمين.'],
            ['الدوسي', 'DEM-'.str_pad((string) $s['reservation_id'], 6, '0', STR_PAD_LEFT)],
            ['حرر بطنجة في', $blank],
            ['توقيع وخاتم الوكالة', 'Yasser Bensbehi · '.$blank],
        ] : [
            ['bensbehicarrental SARL', 'B.'],
            ['Autorisation de sortie du territoire', $reference],
            ['MODÈLE DE DÉMONSTRATION — NON UTILISABLE POUR UN VOYAGE RÉEL. Mentions, assurance et signature finale à valider.', ''],
            ['Agence', 'bensbehicarrental SARL · Rue Tarblous 2, n° 16, Barnes 2, Tanger · +212 661704161'],
            ['Représentant', 'Yasser Bensbehi, gérant · RC / ICE / IF : '.$blank],
            ['Personne autorisée', $name.' · CIN / passeport : '.($c['identity_number'] ?: $blank).' · Permis : '.($c['licence_number'] ?: $blank)],
            ['Adresse', $c['address'] ?: $blank],
            ['Véhicule', $v['make'].' '.$v['model'].' · '.$v['plate']],
            ['Numéro de châssis VIN', $v['vin'] ?: $blank],
            ['Pays de destination et de transit', $countries],
            ['Période autorisée', $date($t['starts_local']).' → '.$date($t['ends_local']).' · heure du Maroc'],
            ['Assurance internationale', $i['insurer'].' · '.$i['insurance_reference']],
            ['Validité du certificat', $i['insurance_starts_on'].' → '.$i['insurance_expires_on']],
            ['Portée du modèle', 'Accord interne limité aux personnes, pays et dates indiqués. Ne remplace ni le certificat d’assurance ni les formalités de frontière. Qualité du signataire, documents du véhicule et garanties à vérifier auprès de l’assureur avant signature.'],
            ['Dossier lié', 'DEM-'.str_pad((string) $s['reservation_id'], 6, '0', STR_PAD_LEFT)],
            ['Fait à Tanger, le', $blank],
            ['Signature et cachet originaux', 'Yasser Bensbehi · '.$blank],
        ];

        return ['rows' => $rows, 'ar' => $ar];
    }

    public function pdf(array $snapshot, string $reference): string
    {
        $data = $this->document($snapshot, $reference);
        $html = view('contracts.foreign-authorization', $data)->render();

        return app(ContractPdf::class)->renderHtml($html);
    }

    public function docx(array $snapshot, string $reference): string
    {
        $d = $this->document($snapshot, $reference);
        $xml = '';
        foreach ($d['rows'] as $index => [$label,$value]) {
            $bold = in_array($index, [0, 1, 2, 16], true);
            $text = $label.($value !== '' ? ' : '.$value : '');
            $xml .= '<w:p><w:pPr>'.($d['ar'] ? '<w:bidi/>' : '').'<w:spacing w:after="160"/>'.($index === 1 ? '<w:jc w:val="center"/>' : '').'</w:pPr><w:r><w:rPr>'.($bold ? '<w:b/>' : '').($d['ar'] ? '<w:rtl/>' : '').'<w:sz w:val="'.($index === 1 ? '30' : '22').'"/><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/></w:rPr><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';
        }
        $path = storage_path('app/private/foreign-'.Str::uuid().'.docx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Word archive unavailable.');
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
            $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$xml.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="850" w:bottom="850" w:left="1134" w:right="1134"/></w:sectPr></w:body></w:document>');
            $zip->close();

            return file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
