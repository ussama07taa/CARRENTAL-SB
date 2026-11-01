'use client';
import {useEffect,useRef,useState} from 'react';
import {api,apiMutation} from '../_lib/auth-client';
type Invoice={id:number;number:string;created_at:string;sha256:string};
export default function InvoicePreview({id}:{id:number}){
 const [html,setHtml]=useState(''),[error,setError]=useState(''),[busy,setBusy]=useState(false),[opened,setOpened]=useState(false);
 const [invoice,setInvoice]=useState<Invoice|null>(null),[enabled,setEnabled]=useState(false),[review,setReview]=useState(''),[checked,setChecked]=useState(false);
 const request=useRef<{hash:string;key:string}|null>(null),lock=useRef(false);
 useEffect(()=>{setHtml('');setError('');setOpened(false);setInvoice(null);setReview('');setChecked(false);request.current=null;
  const ctl=new AbortController();fetch(api+'/api/v1/reservations/'+id+'/invoice',{credentials:'include',cache:'no-store',signal:ctl.signal}).then(async r=>{const b=await r.json();if(!r.ok)throw Error(b.message??'Chargement impossible.');setInvoice(b.invoice);setEnabled(b.enabled);}).catch(e=>{if(!ctl.signal.aborted)setError(e.message);});return()=>ctl.abort();
 },[id]);
 async function download(path:string,name:string){
  const r=await fetch(api+path,{credentials:'include',cache:'no-store'});if(!r.ok){let message='Téléchargement impossible.';try{message=(await r.json()).message||message;}catch{}throw Error(message);}
  const url=URL.createObjectURL(await r.blob());const a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),30000);
 }
 async function load(pdf=false){
  if(lock.current)return;lock.current=true;setBusy(true);setError('');
  try{if(pdf)await download('/api/v1/reservations/'+id+'/invoice-preview?format=pdf','brouillon-location-'+id+'.pdf');
   else{const r=await fetch(api+'/api/v1/reservations/'+id+'/invoice-preview',{credentials:'include',cache:'no-store',headers:{Accept:'application/json'}});const b=await r.json();if(!r.ok)throw Error(b.message??'Préparation impossible.');setHtml(b.html);setReview(b.review_hash);setChecked(false);setEnabled(b.issuance_enabled);setOpened(true);}
  }catch(e){setError((e as Error).message);}finally{lock.current=false;setBusy(false);}
 }
 async function issue(){
  if(lock.current||!checked||!review)return;lock.current=true;setBusy(true);setError('');
  if(request.current?.hash!==review)request.current={hash:review,key:crypto.randomUUID()};
  try{const r=await apiMutation('reservations/'+id+'/invoice','POST',{request_key:request.current.key,review_hash:review,reviewed:true},90000);const b=await r.json();if(!r.ok){if(r.status===409){setChecked(false);setReview('');}throw Error(b.message??'Émission impossible.');}setInvoice(b.invoice);setOpened(false);}
  catch(e){setError(e instanceof Error?e.message:'Réponse incertaine. Réessayez le même envoi : aucun doublon ne sera créé.');}
  finally{lock.current=false;setBusy(false);}
 }
 async function original(){setBusy(true);setError('');try{await download('/api/v1/reservations/'+id+'/invoice/content',(invoice?.number??'facture')+'.pdf');}catch(e){setError((e as Error).message);}finally{setBusy(false);}}
 return <section className="df-document-task"><h3>Facture de location</h3>
  {invoice?<><p><strong>{invoice.number}</strong> · Original émis et conservé.</p><p>Le téléchargement restitue le PDF original. Une modification du dossier ne remplace pas cette facture.</p><button className="primary" disabled={busy} onClick={()=>void original()}>{busy?'Téléchargement…':'Télécharger la facture originale'}</button></>:
  <><p>Client, voiture, prix TTC et TVA repris de l’accord enregistré dans la réservation.</p>
   <p>{enabled?'Vérifiez le brouillon avant émission. Le numéro définitif est attribué à l’enregistrement.':'Prévisualisation disponible. L’émission définitive attend son activation.'}</p>
   <button className="primary" disabled={busy} onClick={()=>void load()}>{busy?'Préparation…':opened?'Actualiser le brouillon':'Prévisualiser la facture'}</button>
   {opened&&<><button className="secondary" disabled={busy} onClick={()=>void load(true)}>Télécharger le brouillon PDF</button><button className="secondary" onClick={()=>setOpened(false)}>Masquer</button>
    <iframe title="Brouillon de facture" sandbox="" srcDoc={html} style={{width:'100%',height:780,border:'1px solid #dce4ef',background:'white',marginTop:16}}/>
    {enabled&&<div style={{padding:16,background:'#f2f6ff',borderRadius:10,marginTop:16}}><label><input type="checkbox" checked={checked} disabled={busy} onChange={e=>setChecked(e.target.checked)}/> J’ai vérifié le client, les informations de l’agence, les montants et la TVA.</label><p>L’émission conserve définitivement ce document. Les corrections nécessitent un document distinct.</p><button className="primary" disabled={busy||!checked||!review} onClick={()=>void issue()}>{busy?'Émission en cours…':'Émettre la facture définitive'}</button></div>}
   </>}
  </>}{error&&<p role="alert" className="dp-warning">{error}</p>}
 </section>;
}
