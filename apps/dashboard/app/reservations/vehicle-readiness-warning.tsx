'use client';
import {useEffect,useRef} from 'react';
import {createPortal} from 'react-dom';
import {AlertTriangle} from 'lucide-react';
import './vehicle-readiness-warning.css';
export type VehicleIssue={kind:'document'|'operation';message:string};
export default function VehicleReadinessWarning({vehicle,issues,onOther,onRetry}:{vehicle:{id:number;make:string;model:string;plate:string};issues:VehicleIssue[];onOther:()=>void;onRetry:()=>void}){
 const dialog=useRef<HTMLDialogElement>(null);
 useEffect(()=>{const origin=document.activeElement as HTMLElement;dialog.current?.showModal();return()=>origin?.focus();},[]);
 return createPortal(<dialog ref={dialog} className="rq-vehicle-warning" aria-labelledby="rq-vehicle-warning-title" onCancel={e=>{e.preventDefault();e.stopPropagation();onOther();}}>
  <div className="rq-vehicle-warning-heading"><AlertTriangle size={24}/><div><h2 id="rq-vehicle-warning-title">Cette voiture ne peut pas être choisie</h2><p>{vehicle.make} {vehicle.model} · {vehicle.plate}</p></div></div>
  <p>Corrigez les éléments suivants avant de poursuivre la demande.</p>
  <ul>{issues.map((issue,i)=><li key={i}><strong>{issue.kind==='document'?'Document':'Disponibilité'}</strong><span>{issue.message}</span></li>)}</ul>
  <p className="rq-vehicle-warning-note">Les dates et les informations de votre demande restent conservées. Le fichier véhicule s’ouvre dans un autre onglet.</p>
  <div className="rq-vehicle-warning-actions">{issues.some(i=>i.kind==='document')&&<a className="primary" target="_blank" rel="noopener noreferrer" href={'/vehicles?vehicle='+vehicle.id+'&section=documents'}>Compléter les documents ↗</a>}<button type="button" className="secondary" onClick={onOther}>Choisir une autre voiture</button><button type="button" className="rq-link" onClick={onRetry}>J’ai corrigé · revérifier</button></div>
 </dialog>,document.body);
}
