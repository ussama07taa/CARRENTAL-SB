<?php
return [
    'enabled' => env('OCR_ENABLED', false),
    'python' => env('OCR_PYTHON', base_path('../../.local/ocr-venv/bin/python')),
    'engine' => env('OCR_ENGINE', base_path('../../scripts/ocr-lab/engine.py')),
];
