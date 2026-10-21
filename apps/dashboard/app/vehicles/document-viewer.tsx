'use client';
import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {Download, X, ZoomIn, ZoomOut, RotateCcw, FileText} from 'lucide-react';
import {api} from '../_lib/auth-client';
import './document-viewer.css';

export default function DocumentViewer({vehicleId,customerId,reservationId,contractFile=false,returnFile=false,fuelFile=false,document,onClose}:{vehicleId?:number;customerId?:number;reservationId?:number;contractFile?:boolean;returnFile?:boolean;fuelFile?:boolean;document:{id:number;kind:string;version:number};onClose:()=>void}){
 const dialog=useRef<HTMLDialogElement>(null);
 const [resource,setResource]=useState<{url:string;mime:string}|null>(null),[error,setError]=useState(''),[attempt,setAttempt]=useState(0),[zoom,setZoom]=useState(1);
 const title=({circulation:'Feuille de circulation',registration:'Carte grise',insurance:'Assurance',inspection:'Visite technique',tax:'Vignette',rental_contract:'Contrat original · test',signed_contract:'Copie signée du contrat · test',departure_photo:'Photo / vidéo du contrôle de départ',return_photo:'Photo / vidéo du retour',return_signature:'Signature du constat de retour',fuel_ticket:'Ticket carburant',identity_front:'Identité · recto',identity_back:'Identité · verso',licence_front:'Permis · recto',licence_back:'Permis · verso'} as Record<string,string>)[document.kind]??'Document';
 useEffect(()=>{const opener=window.document.activeElement as HTMLElement|null,modal=dialog.current;modal?.showModal();return()=>{modal?.close();requestAnimationFrame(()=>{if(opener?.isConnected)opener.focus({preventScroll:true});});};},[]);
 useEffect(()=>{
  const controller=new AbortController();let url='';setResource(null);setError('');setZoom(1);
  void (async()=>{try{
   const response=await fetch(`${api}/api/v1/${reservationId?`reservations/${reservationId}/${fuelFile?'return/fuel':returnFile?'return/files':contractFile?'departure/contract/files':'departure/inspection/photos'}/${document.id}/content`:`${customerId?'customers':'vehicles'}/${customerId??vehicleId}/documents/${document.id}/content`}`,{credentials:'include',cache:'no-store',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(30000)])});
   if(!response.ok)throw new Error(response.status===401?'Votre session a expiré. Reconnectez-vous pour consulter le document.':response.status===404?'Ce fichier n’est plus disponible.':'Impossible de charger le document. Réessayez.');
   const blob=await response.blob();if(controller.signal.aborted)return;
   const mime=blob.type.split(';')[0];if(!['application/pdf','image/jpeg','image/png','image/webp','video/mp4','video/webm','video/quicktime'].includes(mime))throw new Error('Ce format ne peut pas être affiché.');
   url=URL.createObjectURL(blob);setResource({url,mime});
  }catch(e){if(!controller.signal.aborted)setError(e instanceof Error?e.message:'Impossible de charger le document.');}})();
  return()=>{controller.abort();if(url)URL.revokeObjectURL(url);};
 },[vehicleId,customerId,reservationId,contractFile,returnFile,fuelFile,document.id,attempt]);
 const isVideo=resource?.mime.startsWith('video/');
 const pdf=resource?.mime==='application/pdf',extension=isVideo?(resource?.mime==='video/mp4'?'mp4':resource?.mime==='video/quicktime'?'mov':'webm'):pdf?'pdf':resource?.mime==='image/png'?'png':resource?.mime==='image/webp'?'webp':'jpg';
 return createPortal(<dialog ref={dialog} className="document-viewer" aria-labelledby="document-viewer-title" onCancel={event=>{event.preventDefault();event.stopPropagation();onClose();}} onClose={event=>event.stopPropagation()}>
  <header><div><span><FileText size={15}/> DOCUMENT PRIVÉ · VERSION {document.version}</span><h2 id="document-viewer-title">{title}</h2></div><button type="button" onClick={onClose} aria-label="Fermer l’aperçu"><X size={22}/></button></header>
  <div className="dv-toolbar">{resource&&<a href={resource.url} download={`${document.kind}-v${document.version}.${extension}`}><Download size={17}/> Télécharger</a>}{resource&&!pdf&&!isVideo&&<div className="dv-zoom"><button type="button" aria-label="Réduire" disabled={zoom<=1} onClick={()=>setZoom(z=>Math.max(1,z-.5))}><ZoomOut size={18}/></button><span>{Math.round(zoom*100)} %</span><button type="button" aria-label="Agrandir" disabled={zoom>=3} onClick={()=>setZoom(z=>Math.min(3,z+.5))}><ZoomIn size={18}/></button><button type="button" aria-label="Ajuster à l’écran" onClick={()=>setZoom(1)}><RotateCcw size={17}/></button></div>}</div>
  <div className="dv-content" aria-busy={!resource&&!error}>{error?<div className="dv-message" role="alert"><p>{error}</p><button type="button" onClick={()=>setAttempt(a=>a+1)}>Réessayer</button></div>:!resource?<p className="dv-message" role="status">Chargement du document…</p>:isVideo?<video src={resource.url} controls playsInline preload="metadata" style={{maxWidth:'100%',maxHeight:'100%'}} aria-label={title} onError={()=>setError('Lecture indisponible sur cet appareil. Téléchargez la vidéo pour la lire.')}/>:pdf?<iframe title={'Aperçu PDF · '+title} src={resource.url+'#view=FitH'} className="dv-pdf"/>:<div className="dv-image-scroll"><img src={resource.url} alt={title+' · version '+document.version} style={{width:`${zoom*100}%`,maxWidth:'none'}} onError={()=>setError('Cette image ne peut pas être affichée. Réessayez.')}/></div>}</div>
  <footer>{pdf&&resource?<span>Si le PDF ne s’affiche pas, <a href={resource.url} target="_blank" rel="noopener noreferrer">ouvrir le PDF dans le navigateur</a>.</span>:<span>Consultation uniquement · votre document reste inchangé.</span>}<button type="button" onClick={onClose}>Fermer</button></footer>
 </dialog>,window.document.body);
}
