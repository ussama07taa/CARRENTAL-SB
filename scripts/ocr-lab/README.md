# PaddleOCR local evaluation

Scope: local-only pilot authorized on 2026-10-30. Not a production identity-verification feature. No Laravel routes, new permissions, migrations, customer writes or public-site changes.

## Run in the active Ubuntu checkout

```sh
python3 -m venv .local/ocr-venv
.local/ocr-venv/bin/pip install -r scripts/ocr-lab/requirements.txt
.local/ocr-venv/bin/python scripts/ocr-lab/engine.py prepare
.local/ocr-venv/bin/python scripts/ocr-lab/server.py
```

Open http://localhost:3107 . Model preparation downloads official Paddle models to the user's Paddle cache. Document inference runs locally; no cloud OCR. The server binds only to 127.0.0.1. Do not reverse-proxy or deploy this lab. Image and OCR data are kept in process/browser memory, not intentionally written to disk. Swap, OS dumps and explicit downloaded JSON are outside this guarantee. Access logs are disabled and OCR stderr suppressed during requests. Clear the form after use.

## Structure

- `engine.py`: isolated CPU process, French and Arabic PP-OCRv3 models, EXIF correction, 2000px maximum inference dimension, text/confidence/boxes.
- `fields.py`: cautious suggestions from explicit labels, plus a Moroccan CIN recto fallback gated by the identity-card title, birth label and exactly two clear Latin name rows. Numeric CIN candidates must be unique. French and Arabic recognition passes are kept separate; ambiguous fields remain empty. Never infers or validates identity. This is NOT a trained Moroccan document parser.
- `server.py`: same-origin token, host restriction, 10 MB / 20 MP limits, one inference at a time, 150s process timeout, no-store.
- `index.html`: photo preview, language and document choices, editable review, optional reviewed JSON export. One face per request; another read replaces results, no automatic recto/verso merge.

## Evaluation before integration

Use authorized sample documents; compare names, document numbers and dates independently. Test Arabic/French, recto/verso, different layouts, glare/blur/rotation. Mark unknown fields empty. Test actual Moroccan CIN and licences before claiming accuracy. No genuine documents are included in the repository. Synthetic text fixtures prove plumbing only.

Future integration should use existing authenticated tenant-scoped customer/document access and require deliberate review before applying suggestions. Preserve original verification gates; OCR is not authenticity verification. No automatic pickup approval or customer merging.

## Moroccan licence trial

Select `Permis marocain`. Explicit French/Arabic labels suggest names, birth date, licence number, issue date and expiry date. A birth place following the labelled birth date is excluded. CIN numbers and category dates are not treated as licence numbers or document expiry. Fields absent from the photographed face remain empty; recto/verso results are not merged. The operator must review every suggestion.

Validation: 15 unit tests pass; a synthetic labelled French licence text image passed actual French + Arabic Paddle inference for all six fields. This does not establish accuracy on genuine licences; real-document layout testing is still required.

Orientation recovery: when fewer than three fields are found or most text boxes are vertical, the engine compares 0/90/180/270-degree passes using extracted fields, explicit labels and confident text. It keeps a single orientation, then runs the second language in that orientation. Returned boxes use the rotated image coordinates. This is a heuristic, not a guarantee; closely frame the document and avoid glare.

Moroccan bilingual licence recto fallback: requires horizontal country/title, delivery anchor, a unique birth date and exactly two confident Latin name rows in the same column. Explicitly located licence digits are preserved, never repaired; CIN is excluded and expiry is not inferred. A bounded higher-resolution contrast pass is tried for a missing licence number. The original authorized photo was processed in memory for validation, never added to fixtures.

Performance trial: dashboard uses `auto` language mode, skipping Arabic only when all supported fields are found in French; missing fields retain the Arabic fallback. Orientation search stops after a coherent high-confidence name/birth-date group and four extracted fields. Single-run local timings: synthetic labelled licence 8.29s → 5.24s; authorized sideways photo 22.50s → 18.28s, with five fields extracted and absent expiry left empty. A 1600px detailed detector trial lost the licence number and was rejected; 2400px retained. Timings are observations, not an SLA.
