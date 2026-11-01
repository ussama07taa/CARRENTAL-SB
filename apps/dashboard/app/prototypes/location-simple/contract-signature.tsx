'use client';
import {useRef,useState} from 'react';
import {SignaturePad} from '../../reservations/tablet-contract-signature';
import template from './contract-template.json';

// Snapshot of the existing contracts.rental Blade view, rendered with fictional placeholders.
export function contractHtml(values:Record<string,string>,clientSignature='',agencySignature=''){
 const escape=(s:string)=>s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]!));
 let html=template.replace(/__(CLIENT|PHONE|CAR|PLATE|KM|FUEL|START|END|TOTAL|DEPOSIT|DAYS|NOTES|DAILY|RECEIVED|BALANCE)__/g,(_,key:string)=>escape(values[key]??'À compléter'));
 if(clientSignature&&agencySignature){
  const doc=new DOMParser().parseFromString(html,'text/html');
  doc.querySelectorAll('.ref-signatures,.conditions .signs').forEach(group=>{
   [...group.children].forEach((box,index)=>{const img=doc.createElement('img');img.src=index===0?agencySignature:clientSignature;img.alt=index===0?'Signature agence · test':'Signature client · test';img.style.cssText='display:block;width:100%;height:17mm;object-fit:contain';box.append(img);});
  });
  html='<!doctype html>'+doc.documentElement.outerHTML;
 }
 return html;
}
export default function SimpleContractSignature({original,saved,onSave}:{original:string;saved:string;onSave:(html:string)=>void}){
 const [read,setRead]=useState(false),[client,setClient]=useState(''),[agency,setAgency]=useState(''),[preview,setPreview]=useState(''),[accepted,setAccepted]=useState(false),[opened,setOpened]=useState(false);
 const frame=useRef<HTMLIFrameElement>(null);
 function prepare(){const doc=new DOMParser().parseFromString(original,'text/html');doc.querySelectorAll('.ref-signatures,.conditions .signs').forEach(group=>{[...group.children].forEach((box,index)=>{const img=doc.createElement('img');img.src=index===0?agency:client;img.alt=index===0?'Signature agence · test':'Signature client · test';img.style.cssText='display:block;width:100%;height:17mm;object-fit:contain';box.append(img);});});setPreview('<!doctype html>'+doc.documentElement.outerHTML);setAccepted(false);}
 function change(which:'client'|'agency',value:string){if(which==='client')setClient(value);else setAgency(value);setPreview('');setAccepted(false);}
 const shown=saved||preview||original;
 return <section className="ls-sign-flow"><p>Même contrat recto-verso que dans le dossier actuel. Signatures dessinées de test, sans enregistrement dans le dossier réel.</p>
 <button className="ls-text" onClick={()=>setOpened(true)}>{saved?'Consulter la copie signée':'Lire le contrat recto-verso'}</button>
 {(opened||preview||saved)&&<><iframe ref={frame} title="Contrat original recto-verso et signatures" srcDoc={shown} sandbox="allow-same-origin allow-modals"/><button className="ls-text" onClick={()=>frame.current?.contentWindow?.print()}>Imprimer ce contrat / enregistrer en PDF</button></>}
 {saved?<p role="status">Copie signée conservée dans cet essai. Les deux signatures figurent sur les deux pages.</p>:<><label className="ls-check"><input type="checkbox" checked={read} disabled={!opened} onChange={e=>{setRead(e.target.checked);setPreview('');setAccepted(false);}}/>Le client et l’agence ont lu les deux pages de cet exemple.</label><div className="ts-pads"><SignaturePad label="Signature du client" disabled={!read} onChange={v=>change('client',v)}/><SignaturePad label="Signature de l’agence" disabled={!read} onChange={v=>change('agency',v)}/></div><button className="ls-primary" disabled={!read||!client||!agency} onClick={prepare}>Revoir le contrat avec les signatures</button>{preview&&<><label className="ls-check"><input type="checkbox" checked={accepted} onChange={e=>setAccepted(e.target.checked)}/>Les deux parties ont revu les deux pages signées et acceptent de conserver cette copie de test.</label><button className="ls-primary" disabled={!accepted} onClick={()=>onSave(preview)}>Conserver la copie signée · essai</button></>}</>}
 </section>;
}
