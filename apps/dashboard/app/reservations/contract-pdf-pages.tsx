'use client';
import {useEffect,useRef,useState} from 'react';
import './contract-preview.css';

export default function ContractPdfPages({url,title,onReady}:{url:string;title:string;onReady?:(ready:boolean)=>void}) {
 const pages=useRef<HTMLDivElement>(null),callback=useRef(onReady);callback.current=onReady;
 const [error,setError]=useState(''),[loaded,setLoaded]=useState(false),[attempt,setAttempt]=useState(0),[zoomed,setZoomed]=useState(false);
 useEffect(()=>{
  let cancelled=false,task:import('pdfjs-dist').PDFDocumentLoadingTask|undefined;
  const container=pages.current!;container.replaceChildren();setZoomed(false);setLoaded(false);setError('');callback.current?.(false);
  void (async()=>{try{
   const pdfjs=await import('pdfjs-dist');if(cancelled)return;
   pdfjs.GlobalWorkerOptions.workerSrc=new URL('pdfjs-dist/build/pdf.worker.min.mjs',import.meta.url).toString();
   task=pdfjs.getDocument({url});const pdf=await task.promise;
   for(let i=1;i<=pdf.numPages;i++){
    if(cancelled)return;const page=await pdf.getPage(i),base=page.getViewport({scale:1});
    const width=Math.min(1000,Math.max(600,container.clientWidth)),scale=Math.min(window.devicePixelRatio||1,1.5)*width/base.width,viewport=page.getViewport({scale});
    const canvas=document.createElement('canvas');canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);canvas.setAttribute('role','img');canvas.setAttribute('aria-label',`${title} · page ${i} sur ${pdf.numPages}`);
    await page.render({canvas,viewport}).promise;if(cancelled)return;
    const label=document.createElement('p');label.textContent=`Page ${i} / ${pdf.numPages}`;container.append(label,canvas);page.cleanup();
   }
   setLoaded(true);callback.current?.(true);
  }catch(e){if(!cancelled){setError('La lecture du PDF a échoué. Réessayez ou ouvrez le document en grand.');callback.current?.(false);}}})();
  return()=>{cancelled=true;void task?.destroy();container.replaceChildren();};
 },[url,title,attempt]);
 return <div className="contract-pdf" aria-busy={!loaded&&!error}>{!loaded&&!error&&<p role="status">Affichage des pages du contrat…</p>}{loaded&&<button type="button" className="secondary contract-pdf-zoom" aria-pressed={zoomed} onClick={()=>setZoomed(value=>!value)}>{zoomed?'Ajuster à l’écran':'Agrandir les pages'}</button>}<div className={'contract-pdf-pages'+(zoomed?' is-zoomed':'')} ref={pages}/>{error&&<div role="alert"><p>{error}</p><button type="button" className="secondary" onClick={()=>setAttempt(n=>n+1)}>Réessayer l’affichage</button></div>}</div>;
}