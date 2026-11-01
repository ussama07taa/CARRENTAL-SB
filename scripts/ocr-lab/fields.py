"""Conservative, label-based suggestions. Never verifies identity or invents missing fields."""
import re
import unicodedata
from datetime import datetime

LABELS = {
    "last_name": ["nom", "nom de famille", "الاسم العائلي"],
    "first_name": ["prenom", "prenoms", "الاسم الشخصي"],
    "birth_date": ["date et lieu de naissance", "date de naissance", "ne le", "nee le", "n&le", "مزداد بتاريخ", "تاريخ الازدياد", "تاريخ الميلاد"],
    "expiry_date": ["fin de validite", "date d'expiration", "valable jusqu'au", "date de fin de validite", "تاريخ انتهاء الصلاحية"],
    "cin_number": ["numero cin", "n cin", "cin", "رقم البطاقة"],
    "licence_number": ["permis n°", "permis no", "permis n", "n° du permis", "numero du permis", "n du permis", "رقم الرخصة", "رقم رخصة السياقة"],
    "issue_date": ["date de delivrance", "delivre le", "تاريخ التسليم", "تاريخ الإصدار"],
}
def normalized(text):
    return ''.join(c for c in unicodedata.normalize('NFD', text.lower()) if unicodedata.category(c) != 'Mn').strip()

def extract_labelled_fields(lines, kind):
    fields = {}
    for key, labels in LABELS.items():
        if (key == 'issue_date' and kind != 'permis') or (key == 'cin_number' and kind != 'cin') or (key == 'licence_number' and kind != 'permis'):
            continue
        candidates = []
        for i, line in enumerate(lines):
            source = line['text'].strip()
            value = None
            evidence = line
            for label in labels:
                label = normalized(label)
                match = re.match(r'^' + re.escape(label).replace(r'\ ', r'\s*') + r'(?:\s*[:：]\s*|\s+)(.+)$', normalized(source))
                if match:
                    # Preserve original script/case; normalization is only for matching the label.
                    value = source[-len(match.group(1)):].strip()
                elif re.sub(r'\s+', '', normalized(source).rstrip(' :：')) == re.sub(r'\s+', '', label) and i + 1 < len(lines):
                    following = lines[i + 1]
                    # Only suggest a neighbouring line below the label in the same column.
                    x, y, w, h = line.get('box', [0, 0, 0, 0])
                    nx, ny, _, _ = following.get('box', [0, 99999, 0, 0])
                    if (0 <= ny-y <= max(3*h, 40) and abs(nx-x) < max(w, 100)) or key.endswith('_date'):
                        value = following['text'].strip()
                        evidence = following
                if value:
                    break
            if not value or len(value) > 100:
                continue
            if any(normalized(value).rstrip(' :：') in [normalized(label) for label in v] for v in LABELS.values()):
                continue
            if kind == 'permis' and key == 'birth_date':
                # Birth place can share this explicitly labelled field. Keep only its date.
                date_match = re.match(r'^(\d{2}[./-]\d{2}[./-]\d{4})(?:\s|$)', value)
                if date_match:
                    value = date_match.group(1)
            if key.endswith('_date'):
                match = re.fullmatch(r'(\d{2})[./-](\d{2})[./-](\d{4})', value)
                if not match:
                    continue
                try:
                    value = datetime.strptime('/'.join(match.groups()), '%d/%m/%Y').date().isoformat()
                except ValueError:
                    continue
            candidates.append({'value': value, 'confidence': round(evidence['confidence'], 3), 'source': evidence['text'], 'review_required': True})
        # Conflicting values remain blank rather than silently selecting one.
        unique = {c['value']: c for c in candidates}
        fields[key] = next(iter(unique.values())) if len(unique) == 1 else None
    return fields


def date_value(text):
    match = re.fullmatch(r'(\d{2})[./-](\d{2})[./-](\d{4})', text.strip())
    if not match:
        return None
    try:
        return datetime.strptime('/'.join(match.groups()), '%d/%m/%Y').date().isoformat()
    except ValueError:
        return None


def suggestion(line, value=None, method='cin_layout'):
    return {'value':value if value is not None else line['text'].strip(),
            'confidence':round(line['confidence'],3), 'source':line['text'],
            'method':method, 'review_required':True}


def cin_layout_fields(lines):
    """Known Moroccan CIN recto: title, given name, family name, birth anchor.
    Never apply this order to a licence or to arbitrary unlabeled text.
    """
    output={}
    compact=lambda text: re.sub(r"[^a-z0-9]", '', normalized(text))
    titles=[i for i,line in enumerate(lines) if 'cartenationale' in compact(line['text']) and 'identite' in compact(line['text'])]
    if not titles:
        return output
    title=titles[0]
    anchors=[i for i in range(title+1,len(lines)) if compact(lines[i]['text']) in ['nele','neele','nle']]
    if anchors:
        birth=anchors[0]
        # Only the two clear Latin name rows between the identity title and birth label.
        candidates=[line for line in lines[title+1:birth]
                    if line['confidence']>=.9 and re.fullmatch(r"[A-ZÀ-ÖØ-Ý][A-ZÀ-ÖØ-Ý '\-]{1,59}",line['text'].strip())
                    and not any(word in compact(line['text']) for word in ['royaume','maroc','carte','identite','nationalite'])]
        if len(candidates)==2:
            output['first_name']=suggestion(candidates[0])
            output['last_name']=suggestion(candidates[1])
        if birth+1<len(lines):
            value=date_value(lines[birth+1]['text'])
            if value:
                output['birth_date']=suggestion(lines[birth+1],value)
    ids=[line for line in lines[title+1:] if line['confidence']>=.85 and re.fullmatch(r'[A-Z]{1,2}\s?\d{4,8}',line['text'].strip())]
    distinct={re.sub(r'\s','',line['text']):line for line in ids}
    if len(distinct)==1:
        value,line=next(iter(distinct.items()))
        output['cin_number']=suggestion(line,value)
    return output


def permis_layout_fields(lines):
    """Conservative fallback for the bilingual Moroccan recto with delivery line.
    Requires horizontal title, country, delivery anchor and a unique name/date column.
    """
    compact=lambda text: re.sub(r'[^a-z0-9]', '', normalized(text))
    horizontal=[l for l in lines if len(l.get('box', []))==4 and l['box'][2]>l['box'][3]]
    titles=[l for l in horizontal if 'permisdeconduire' in compact(l['text'])]
    if len(titles)!=1 or not any('royaumedumaroc' in compact(l['text']) for l in horizontal):
        return {}
    title=titles[0]; tx,ty,tw,th=title['box']
    delivery=[l for l in horizontal if compact(l['text']).startswith('delivrea') and l['confidence']>=.8 and l['box'][1]>ty]
    if len(delivery)!=1:
        return {}
    anchor=delivery[0]; ax,ay,aw,ah=anchor['box']
    tolerance=max(ah*3, aw*.3)
    column=[l for l in horizontal if abs(l['box'][0]-ax)<tolerance and ty+th<l['box'][1]<ay and l['confidence']>=.9]
    births=[l for l in column if date_value(l['text'])]
    if len(births)!=1:
        return {}
    birth=births[0]
    names=[l for l in column if l['box'][1]<birth['box'][1]
           and re.fullmatch(r"[A-ZÀ-ÖØ-Ý][A-ZÀ-ÖØ-Ý '\-]{1,59}",l['text'].strip())
           and not any(w in compact(l['text']) for w in ['royaume','maroc','permis','conduire'])]
    if len(names)!=2:
        return {}
    names.sort(key=lambda l:l['box'][1])
    out={k:suggestion(l,v,'permis_layout') for k,l,v in [
        ('first_name',names[0],None),('last_name',names[1],None),('birth_date',birth,date_value(birth['text']))]}
    issued=[l for l in horizontal if l['confidence']>=.9 and date_value(l['text'])
            and ay<l['box'][1]<ay+max(ah*3,50) and ax-tolerance<l['box'][0]<ax+aw]
    if len(issued)==1:
        out['issue_date']=suggestion(issued[0],date_value(issued[0]['text']),'permis_layout')
    numbers=[]
    for l in horizontal:
        if l['confidence']<.8 or not (ty<l['box'][1]<names[0]['box'][1]) or l['box'][0]<=names[0]['box'][0]:
            continue
        # A known OCR confusion in the printed Permis label; digits are never repaired.
        if not re.match(r'^(?:permis|pomi)',normalized(l['text'])):
            continue
        match=re.search(r'(?<!\d)(\d{1,3}/\d{4,8})(?!\d)',l['text'])
        if match: numbers.append((l,match.group(1)))
    if len(numbers)==1:
        out['licence_number']=suggestion(numbers[0][0],numbers[0][1],'permis_layout')
    return out


def extract_fields(lines, kind):
    # Keep OCR passes separate. Latin strings from an Arabic recognizer are not
    # authoritative alternatives to the French recognizer's Latin reading.
    french=[line for line in lines if line.get('language','fr')=='fr']
    arabic=[line for line in lines if line.get('language')=='ar' and (re.search(r'[\u0600-\u06ff]',line['text']) or date_value(line['text']))]
    primary=extract_labelled_fields(french,kind)
    if kind=='cin':
        for key,value in cin_layout_fields(french).items():
            if not primary.get(key):
                primary[key]=value
    if kind=='permis':
        for key,value in permis_layout_fields(french).items():
            if not primary.get(key):
                primary[key]=value
    alternate=extract_labelled_fields(arabic,kind)
    for key,value in alternate.items():
        if not primary.get(key):
            primary[key]=value
    return primary
