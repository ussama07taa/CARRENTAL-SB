'use client';

import {useEffect, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import {ArrowUpRight, Bell, Check, ChevronLeft, ChevronRight, FileClock, Landmark, RefreshCw, ShieldCheck, Wrench, X} from 'lucide-react';
import {api} from '../_lib/auth-client';

type Alert = {key:string;group:string;title:string;state:string;date:string|null;vehicle:{plate:string;make:string;model:string}};
type Snapshot = {summary:{urgent:number};data:Alert[];total:number;page:number;last_page:number;generated_at:string};
const states:Record<string,string> = {overdue:'En retard',due:'Aujourd’hui',urgent:'Sous 7 jours',soon:'Sous 30 jours',garage:'Au garage',missing:'À renseigner',missing_file:'Pièce manquante',deferred:'Travail reporté',credit:'À régulariser',scheduled:'Planifié'};

export default function NotificationCenter() {
  const [open,setOpen]=useState(false),[page,setPage]=useState(1),[revision,setRevision]=useState(0);
  const [data,setData]=useState<Snapshot|null>(null),[busy,setBusy]=useState(true),[error,setError]=useState(''),[denied,setDenied]=useState(false);
  const trigger=useRef<HTMLButtonElement>(null),dialog=useRef<HTMLDialogElement>(null);
  useEffect(()=>{
    if(!open)return;
    dialog.current?.showModal();
    return()=>trigger.current?.focus();
  },[open]);
  useEffect(()=>{
    const controller=new AbortController(); setBusy(true);setError('');
    async function load(){
      try {
        const response=await fetch(`${api}/api/v1/dashboard/overview?horizon=30&page=${page}`,{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([controller.signal,AbortSignal.timeout(15000)])});
        if(controller.signal.aborted)return;
        if(response.status===401){setData(null);window.location.replace('/login');return;}
        if(response.status===403){setDenied(true);setData(null);return;}
        if(!response.ok)throw new Error('Les notifications sont indisponibles. Réessayez.');
        const snapshot:Snapshot=await response.json();
        if(!controller.signal.aborted){setData(snapshot);setDenied(false);}
      }catch{if(!controller.signal.aborted){setError('Impossible d’actualiser les notifications.');setData(null);}}
      finally{if(!controller.signal.aborted)setBusy(false);}
    }
    void load();return()=>controller.abort();
  },[page,revision]);
  useEffect(()=>{
    if(denied)return;
    const refresh=()=>{if(document.visibilityState==='visible')setRevision(value=>value+1);};
    const timer=setInterval(refresh,60000);document.addEventListener('visibilitychange',refresh);
    return()=>{clearInterval(timer);document.removeEventListener('visibilitychange',refresh);};
  },[denied]);
  const urgent=data?.summary.urgent;
  return <>
    <button ref={trigger} className="app-bell" aria-label="Notifications" aria-haspopup="dialog" aria-expanded={open} aria-describedby="notification-badge-description" onClick={()=>{setPage(1);setRevision(value=>value+1);setOpen(true);}}><Bell size={20} aria-hidden="true"/>{!!urgent&&!error&&<span className="app-bell-badge" aria-hidden="true">{urgent>99?'99+':urgent}</span>}</button>
    <span id="notification-badge-description" className="sr-only">{error?'Notifications indisponibles':urgent!==undefined?`${urgent} échéance(s) en retard ou à traiter aujourd’hui`:'Consulter le suivi de l’agence'}</span>
    {open&&createPortal(<dialog ref={dialog} className="notification-dialog" aria-labelledby="notification-title" onCancel={()=>setOpen(false)} onClick={event=>{if(event.target!==event.currentTarget)return;const rect=event.currentTarget.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)setOpen(false);}}>
      <header><div><span className="notification-eyebrow">LE SUIVI DE VOTRE AGENCE</span><h2 id="notification-title">Notifications</h2></div><button className="notification-close" aria-label="Fermer les notifications" onClick={()=>setOpen(false)}><X size={20}/></button></header>
      <div className="notification-body" aria-busy={busy}>
        {busy?<p className="notification-message" role="status">Chargement du suivi…</p>:error?<div className="notification-message" role="alert"><p>{error}</p><button className="secondary" onClick={()=>setRevision(value=>value+1)}>Réessayer</button></div>:denied?<div className="notification-message"><ShieldCheck size={24}/><p>Ce suivi est réservé au responsable de l’agence.</p></div>:data&&<>
          <div className="notification-summary"><Bell size={18}/><div><strong>{data.summary.urgent} échéance(s) prioritaire(s)</strong><p>En retard ou à traiter aujourd’hui.</p></div></div>
          <p className="notification-intro">Documents, entretiens et crédits : retards d’abord, puis suivi sous 30 jours et dossiers à compléter.</p>
          {data.total===0?<div className="notification-message"><Check size={26}/><p>Aucun élément à suivre dans cette période.</p></div>:<ul className="notification-list">{data.data.map(row=>{const Icon=row.group==='documents'?FileClock:row.group==='credit'?Landmark:Wrench;return <li key={row.key}><a href={'/?'+new URLSearchParams({q:row.vehicle.plate,tab:row.group})+'#suivi'}><span className={'notification-symbol '+row.state}><Icon size={18}/></span><div><span className="notification-row-top"><strong>{row.title}</strong><span className={'notification-status '+row.state}>{states[row.state]??'À vérifier'}</span></span><p>{row.vehicle.make} {row.vehicle.model} · {row.vehicle.plate}</p><small>Voir le suivi de ce véhicule <ArrowUpRight size={12}/></small></div></a></li>;})}</ul>}
          {data.last_page>1&&<div className="notification-pages"><span>{data.total} éléments · {data.page} / {data.last_page}</span><button disabled={data.page<=1} aria-label="Notifications précédentes" onClick={()=>{setPage(data.page-1);dialog.current?.querySelector('.notification-body')?.scrollTo(0,0);}}><ChevronLeft size={17}/></button><button disabled={data.page>=data.last_page} aria-label="Notifications suivantes" onClick={()=>{setPage(data.page+1);dialog.current?.querySelector('.notification-body')?.scrollTo(0,0);}}><ChevronRight size={17}/></button></div>}
        </>}
      </div>
      <footer><span>{data&&!busy?'Actualisé à '+new Intl.DateTimeFormat('fr-FR',{timeStyle:'short',timeZone:'Africa/Casablanca'}).format(new Date(data.generated_at)):'Suivi de l’agence'}</span><button disabled={busy} onClick={()=>setRevision(value=>value+1)}><RefreshCw size={14}/> Actualiser</button></footer>
    </dialog>,document.body)}
  </>;
}
