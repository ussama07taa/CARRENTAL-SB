'use client';
import {useEffect,useRef,useState} from 'react';
import ContractPdfPages from './contract-pdf-pages';
import {api} from '../_lib/auth-client';
import './contract-preview.css';

/** The stored original/copy is read here; loading never verifies its contents. */
export default function ContractPreview({reservation,fileId,title,onReady}:{reservation:number;fileId:number;title:string;onReady?:(ready:boolean)=>void}) {
 const [resource,setResource]=useState<{id:number;url:string;mime:string}|null>(null),[error,setError]=useState(''),[attempt,setAttempt]=useState(0);
 const callback=useRef(onReady);callback.current=onReady;
 useEffect(()=>{
  const controller=new AbortController();let url='';setResource(null);setError('');callback.current?.(false);
  void (async()=>{try {
   const response=await fetch(`${api}/api/v1/reservations/${reservation}/departure/contract/files/${fileId}/content`,{credentials:'include',cache:'no-store',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(30000)])});
   if(!response.ok)throw Error('Le document n’a pas pu être chargé. Réessayez avant de continuer.');
   const blob=await response.blob();if(controller.signal.aborted)return;
   const mime=blob.type.split(';')[0];if(!['application/pdf','image/jpeg','image/png','image/webp'].includes(mime))throw Error('Ce document ne peut pas être affiché.');
   url=URL.createObjectURL(blob);setResource({id:fileId,url,mime});
  }catch(e){if(!controller.signal.aborted){setError((e as Error).message);callback.current?.(false);}}})();
  return()=>{controller.abort();if(url)URL.revokeObjectURL(url);};
 },[reservation,fileId,attempt]);
 const current=resource?.id===fileId?resource:null;
 return <section className="contract-preview" aria-label={title} aria-busy={!current&&!error}>
  {error?<div role="alert"><p>{error}</p><button type="button" className="secondary" onClick={()=>setAttempt(n=>n+1)}>Réessayer le document</button></div>:!current?<p role="status">Chargement du document…</p>:<>
   {current.mime==='application/pdf'?<ContractPdfPages url={current.url} title={title} onReady={ready=>callback.current?.(ready)}/>:<img src={current.url} alt={title} onLoad={()=>callback.current?.(true)} onError={()=>{setError('L’image ne peut pas être affichée.');callback.current?.(false);}}/>}
   <div className="contract-preview-links"><a href={current.url} target="_blank" rel="noopener noreferrer">Ouvrir en grand / imprimer</a><a href={current.url} download={`contrat-${fileId}.${current.mime==='application/pdf'?'pdf':current.mime.split('/')[1]}`}>Télécharger</a></div>
  </>}
 </section>;
}