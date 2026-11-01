"""CPU-only OCR process. Reads an image on stdin; returns JSON on stdout."""
import contextlib
import io
import json
import os
import sys
from pathlib import Path
os.environ.setdefault('PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK', 'True')
os.environ.setdefault('OMP_NUM_THREADS', '2')
os.environ.setdefault('PADDLE_PDX_MODEL_SOURCE', 'BOS')
from fields import extract_fields

def create_engine(language, prepare=False):
    from paddleocr import PaddleOCR
    options = {}
    if not prepare:
        cache = Path.home()/'.paddlex/official_models'
        detector = cache/'PP-OCRv3_mobile_det'
        recognizer = cache/('arabic_PP-OCRv3_mobile_rec' if language=='ar' else 'latin_PP-OCRv3_mobile_rec')
        if not all((folder/'inference.pdiparams').exists() for folder in [detector,recognizer]):
            raise RuntimeError('Run engine.py prepare before reading documents')
        options={'text_detection_model_name':detector.name,'text_recognition_model_name':recognizer.name,'text_detection_model_dir':str(detector),'text_recognition_model_dir':str(recognizer)}
    return PaddleOCR(**options, lang=language, ocr_version='PP-OCRv3', device='cpu', cpu_threads=2,
        use_doc_orientation_classify=False, use_doc_unwarping=False,
        use_textline_orientation=False, enable_mkldnn=False)

def read_image(data, language, kind):
    from PIL import Image, ImageOps
    import numpy as np
    image = ImageOps.exif_transpose(Image.open(io.BytesIO(data))).convert('RGB')
    image.thumbnail((2000, 2000))
    def recognize(engine, oriented, lang, detailed=False):
        pixels = np.array(oriented)[:, :, ::-1].copy()
        output = []
        options = {'text_det_limit_side_len':2400,'text_det_limit_type':'max'} if detailed else {}
        for result in engine.predict(pixels, **options):
            for text, score, box in zip(result['rec_texts'], result['rec_scores'], result['rec_boxes']):
                if str(text).strip():
                    x1, y1, x2, y2 = [int(v) for v in box]
                    output.append({'text':str(text), 'confidence':float(score), 'language':lang, 'box':[x1,y1,x2-x1,y2-y1]})
        return output

    # Score complete OCR passes, never mix fields from different orientations.
    # Explicit document labels are stronger evidence than isolated readable words.
    from fields import normalized, LABELS
    def quality(lines):
        fields = extract_fields(lines, kind)
        labelled = sum(any(normalized(line['text']).startswith(normalized(label))
                           for labels in LABELS.values() for label in labels)
                       for line in lines if line['confidence'] >= .7)
        clear = sum(min(len(line['text']), 40) * line['confidence']
                    for line in lines if line['confidence'] >= .85)
        return (sum(bool(value) for value in fields.values()), labelled, clear)

    first_lang = 'ar' if language == 'ar' else 'fr'
    engine = create_engine(first_lang)
    lines = recognize(engine, image, first_lang)
    rotation = 0
    best = quality(lines)
    # Retry sideways/upside-down photos when the first pass yields few fields.
    vertical = sum(line['box'][3] > 2 * line['box'][2] for line in lines)
    if best[0] < 3 or vertical > len(lines) / 2:
        for angle in [90, 180, 270]:
            candidate = recognize(engine, image.rotate(angle, expand=True), first_lang)
            score = quality(candidate)
            if score > best:
                lines, best, rotation = candidate, score, angle
            found = extract_fields(candidate,kind)
            if score[0] >= 4 and all(found.get(k) and found[k]['confidence'] >= .9 for k in ['first_name','last_name','birth_date']):
                # A coherent upright document is enough; do not OCR two more rotations.
                break
    selected_image = image.rotate(rotation, expand=True)
    if kind == 'permis' and not extract_fields(lines,kind).get('licence_number'):
        enhanced = ImageOps.autocontrast(selected_image)
        scale = min(2, 3200 / max(enhanced.size))
        enhanced = enhanced.resize((round(enhanced.width*scale), round(enhanced.height*scale)))
        candidate = recognize(engine, enhanced, first_lang, detailed=True)
        if quality(candidate) > best:
            lines, selected_image = candidate, enhanced
    del engine
    complete = all(extract_fields(lines,kind).get(key) for key in (['first_name','last_name','birth_date','cin_number','expiry_date'] if kind=='cin' else ['first_name','last_name','birth_date','licence_number','issue_date','expiry_date']))
    if language == 'both' or (language == 'auto' and not complete):
        engine = create_engine('ar')
        lines.extend(recognize(engine, selected_image, 'ar'))
        del engine
    return {'engine':'PaddleOCR PP-OCRv3 CPU', 'rotation_degrees_ccw':rotation,
            'lines':lines, 'fields':extract_fields(lines,kind), 'review_required':True, 'saved':False}

if __name__ == '__main__':
    with contextlib.redirect_stdout(sys.stderr):
        if len(sys.argv)>1 and sys.argv[1]=='prepare':
            for lang in ['fr','ar']:
                create_engine(lang, prepare=True)
            result={'ready':True}
        else:
            result=read_image(sys.stdin.buffer.read(), sys.argv[1], sys.argv[2])
    print(json.dumps(result,ensure_ascii=False))
