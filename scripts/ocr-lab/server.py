"""Local evaluation tool only. No accounts, database writes, persistence, or public deployment."""
import asyncio
import io
import json
import secrets
import sys
import warnings
from pathlib import Path
from fastapi import FastAPI, Request, HTTPException
from fastapi.responses import HTMLResponse, Response
from PIL import Image, UnidentifiedImageError
from starlette.middleware.trustedhost import TrustedHostMiddleware

BASE=Path(__file__).resolve().parent
TOKEN=secrets.token_urlsafe(32)
LIMIT=10*1024*1024
Image.MAX_IMAGE_PIXELS=20_000_000
app=FastAPI(docs_url=None,redoc_url=None,openapi_url=None)
app.add_middleware(TrustedHostMiddleware,allowed_hosts=['localhost','127.0.0.1','testserver'])
lock=asyncio.Lock()

@app.middleware('http')
async def private_response(request, call_next):
    response=await call_next(request)
    response.headers['Cache-Control']='no-store'
    response.headers['X-Content-Type-Options']='nosniff'
    response.headers['Referrer-Policy']='no-referrer'
    response.headers['Content-Security-Policy']="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' blob:; frame-ancestors 'none'; connect-src 'self'"
    return response

@app.get('/',response_class=HTMLResponse)
def home():
    return (BASE/'index.html').read_text().replace('__TOKEN__',TOKEN)

@app.get('/health')
def health():
    return {'status':'ok','mode':'local-evaluation','persistent_storage':False}

@app.post('/recognize')
async def recognize(request:Request, language:str='both', kind:str='cin'):
    if not secrets.compare_digest(request.headers.get('x-ocr-token',''),TOKEN):
        raise HTTPException(403,'Accès local non autorisé. Rechargez la page.')
    if language not in ['fr','ar','both'] or kind not in ['cin','permis']:
        raise HTTPException(422,'Choisissez CIN ou permis et une langue disponible.')
    if lock.locked():
        raise HTTPException(429,'Une lecture est déjà en cours. Réessayez dans un instant.')
    data=bytearray()
    async for chunk in request.stream():
        data.extend(chunk)
        if len(data)>LIMIT:
            raise HTTPException(413,'Image trop lourde : 10 Mo maximum.')
    try:
        with warnings.catch_warnings():
            warnings.simplefilter('error', Image.DecompressionBombWarning)
            with Image.open(io.BytesIO(data)) as image:
                if image.format not in ['JPEG','PNG','WEBP'] or min(image.size)<100:
                    raise ValueError()
                image.verify()
    except (UnidentifiedImageError,ValueError,OSError,Image.DecompressionBombError,Image.DecompressionBombWarning):
        raise HTTPException(422,'Image non valide. Utilisez une photo JPG, PNG ou WebP lisible (20 mégapixels maximum).')
    async with lock:
        process=await asyncio.create_subprocess_exec(sys.executable,str(BASE/'engine.py'),language,kind,
            stdin=asyncio.subprocess.PIPE,stdout=asyncio.subprocess.PIPE,stderr=asyncio.subprocess.DEVNULL)
        try:
            output,_=await asyncio.wait_for(process.communicate(bytes(data)),timeout=150)
        except (TimeoutError,asyncio.CancelledError):
            if process.returncode is None:
                process.kill()
            await process.wait()
            raise HTTPException(504,'Lecture trop longue. Réessayez avec une photo plus petite.')
        if process.returncode:
            raise HTTPException(503,'Le moteur OCR est indisponible. Vérifiez son installation locale.')
        try:
            result=json.loads(output)
        except ValueError:
            raise HTTPException(503,'Réponse OCR invalide.')
        return result

if __name__=='__main__':
    import uvicorn
    uvicorn.run(app,host='127.0.0.1',port=3107,access_log=False)
