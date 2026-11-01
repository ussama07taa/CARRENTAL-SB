import unittest
from fastapi.testclient import TestClient
from fields import extract_fields
from server import app, TOKEN


def lines(*texts):
    return [{'text':t, 'confidence':.95, 'box':[10,i*25,200,20]} for i,t in enumerate(texts)]

class SuggestionsTest(unittest.TestCase):
    def test_licence_layout_uses_geometry_and_keeps_expiry_empty(self):
        def row(text,x,y,w=180,h=20):
            return dict(text=text,confidence=.96,box=[x,y,w,h],language='fr')
        sample=[row('ROYAUME DU MAROC',100,10),row('PERMISDECONDUIRE',100,40),
                row('Pomi12/345678',430,70),row('AMINE',300,100),
                row('EXEMPLE',300,150),row('12/03/1990',300,200),
                row('ZZ000000',600,200),row('TANGER',300,240),
                row('delivre a Tanger',300,280),row('15/04/2020',340,310)]
        f=extract_fields(sample,'permis')
        self.assertEqual(f['first_name']['value'],'AMINE')
        self.assertEqual(f['last_name']['value'],'EXEMPLE')
        self.assertEqual(f['birth_date']['value'],'1990-03-12')
        self.assertEqual(f['issue_date']['value'],'2020-04-15')
        self.assertEqual(f['licence_number']['value'],'12/345678')
        self.assertIsNone(f['expiry_date'])
        for changed in [sample[2:], sample+[row('ANOTHER',300,175)]]:
            self.assertIsNone(extract_fields(changed,'permis')['first_name'])
        vertical=[dict(l,box=[l['box'][0],l['box'][1],20,180]) for l in sample]
        self.assertIsNone(extract_fields(vertical,'permis')['first_name'])

    def test_labelled_fields_preserve_case_and_validate_dates(self):
        fields=extract_fields(lines('Nom : EXEMPLE','Prénom : Test','Date de naissance : 12/03/1990','CIN : ZZ000000'),'cin')
        self.assertEqual(fields['last_name']['value'],'EXEMPLE')
        self.assertEqual(fields['first_name']['value'],'Test')
        self.assertEqual(fields['birth_date']['value'],'1990-03-12')
        self.assertTrue(fields['cin_number']['review_required'])
    def test_no_unlabelled_or_impossible_date_guess(self):
        fields=extract_fields(lines('EXEMPLE','31/02/2000','Date de naissance : 31/02/2000'),'cin')
        self.assertIsNone(fields['birth_date'])
        self.assertIsNone(fields['last_name'])
    def test_conflicts_remain_empty(self):
        self.assertIsNone(extract_fields(lines('Nom : A','Nom : B'),'cin')['last_name'])
    def test_next_line_and_arabic(self):
        fields=extract_fields(lines('الاسم العائلي','تجربة'),'cin')
        self.assertEqual(fields['last_name']['value'],'تجربة')
    def test_arabic_ocr_without_spaces(self):
        fields=extract_fields(lines('الاسمالعائلي:تجربة'),'cin')
        self.assertEqual(fields['last_name']['value'],'تجربة')
    def test_document_type_separation(self):
        fields=extract_fields(lines('CIN : ZZ000000','Numéro du permis : TEST0000'),'permis')
        self.assertNotIn('cin_number',fields)
        self.assertEqual(fields['licence_number']['value'],'TEST0000')

    def test_moroccan_cin_unlabelled_names_with_birth_anchor(self):
        sample=lines("ROYAUMEDU MAROC", "CARTE NATIONALED'IDENTITE", 'AMINE', 'EXEMPLE', 'N&le', '12.03.1990', "Valablejusqu'au", '12.03.2030', 'ZZ000000')
        for line in sample:
            line['language']='fr'
        # An Arabic pass can misread the same Latin text: do not create a conflict.
        sample += [dict(line,language='ar',text=line['text'].replace('EXEMPLE','EXEMPL')) for line in sample]
        fields=extract_fields(sample,'cin')
        self.assertEqual(fields['first_name']['value'],'AMINE')
        self.assertEqual(fields['last_name']['value'],'EXEMPLE')
        self.assertEqual(fields['birth_date']['value'],'1990-03-12')
        self.assertEqual(fields['expiry_date']['value'],'2030-03-12')
        self.assertEqual(fields['cin_number']['value'],'ZZ000000')
    def test_cin_layout_not_applied_to_permis_or_arbitrary_names(self):
        sample=lines("CARTE NATIONALE D'IDENTITE",'AMINE','EXEMPLE','Ne le','12.03.1990')
        self.assertIsNone(extract_fields(sample,'permis')['first_name'])
        self.assertIsNone(extract_fields(sample[1:],'cin')['first_name'])
    def test_ambiguous_names_are_not_guessed(self):
        sample=lines("CARTE NATIONALE D'IDENTITE",'AMINE','EXEMPLE','AUTRE','Ne le','12.03.1990')
        self.assertIsNone(extract_fields(sample,'cin')['first_name'])

    def test_moroccan_licence_labels_and_separate_dates(self):
        sample=lines('PERMIS DE CONDUIRE','Permis N° : 123456/78','Prénom : AMINE','Nom : EXEMPLE','Date et Lieu de naissance : 12.03.1990 TANGER','Date de délivrance : 15.04.2020','Fin de validité : 15.04.2030','N° C.N.I.E. : ZZ000000')
        fields=extract_fields(sample,'permis')
        self.assertEqual(fields['licence_number']['value'],'123456/78')
        self.assertEqual(fields['first_name']['value'],'AMINE')
        self.assertEqual(fields['last_name']['value'],'EXEMPLE')
        self.assertEqual(fields['birth_date']['value'],'1990-03-12')
        self.assertEqual(fields['issue_date']['value'],'2020-04-15')
        self.assertEqual(fields['expiry_date']['value'],'2030-04-15')
        self.assertNotIn('cin_number',fields)
    def test_licence_categories_do_not_imply_document_expiry(self):
        sample=lines('PERMIS DE CONDUIRE','B 15/04/2020','C 15/04/2021','ZZ000000')
        fields=extract_fields(sample,'permis')
        self.assertIsNone(fields['licence_number'])
        self.assertIsNone(fields['expiry_date'])
        self.assertIsNone(fields['issue_date'])

class BoundaryTest(unittest.TestCase):
    def setUp(self):
        self.client=TestClient(app)
        self.headers={'x-ocr-token':TOKEN}
    def test_token_required(self):
        self.assertEqual(self.client.post('/recognize',content=b'anything').status_code,403)
    def test_invalid_image_and_parameters(self):
        self.assertEqual(self.client.post('/recognize',content=b'not-image',headers=self.headers).status_code,422)
        self.assertEqual(self.client.post('/recognize?kind=other',content=b'anything',headers=self.headers).status_code,422)
    def test_oversized_body_rejected(self):
        self.assertEqual(self.client.post('/recognize',content=b'x'*(10*1024*1024+1),headers=self.headers).status_code,413)
    def test_private_no_store_and_host(self):
        self.assertEqual(self.client.get('/').headers['cache-control'],'no-store')
        self.assertEqual(self.client.get('/',headers={'host':'foreign.example'}).status_code,400)

if __name__=='__main__':
    unittest.main()


class FastReadingTest(unittest.TestCase):
    def test_auto_skips_second_language_only_when_fields_are_complete(self):
        from unittest.mock import patch
        from PIL import Image
        from io import BytesIO
        from engine import read_image
        image=BytesIO();Image.new('RGB',(600,400),'white').save(image,format='PNG')
        texts=['Nom : EXEMPLE','Prénom : AMINE','Date de naissance : 12/03/1990',
               'Numéro du permis : 12/345678','Date de délivrance : 15/04/2020','Fin de validité : 15/04/2030']
        class Fake:
            def __init__(self, rows): self.rows=rows
            def predict(self, pixels, **kwargs):
                return [{'rec_texts':self.rows,'rec_scores':[.99]*len(self.rows),
                         'rec_boxes':[[10,i*30,350,i*30+20] for i in range(len(self.rows))]}]
        for rows, expected in [(texts,['fr']), (texts[:-1],['fr','ar'])]:
            calls=[]
            def factory(language):
                calls.append(language);return Fake(rows)
            with patch('engine.create_engine',side_effect=factory):
                result=read_image(image.getvalue(),'auto','permis')
            self.assertEqual(calls,expected)
            self.assertEqual(result['fields']['licence_number']['value'],'12/345678')
