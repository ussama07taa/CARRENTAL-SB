'use client';

import {useEffect, useState} from 'react';
import {ArrowUpRight, CarFront, ChevronLeft, ChevronRight} from 'lucide-react';
import {api} from '../_lib/auth-client';

type Departure = {id:number;customer:string;vehicle:string;plate:string;at:string;state:string;href:string};
type Snapshot = {total:number;urgent:number;page:number;last_page:number;data:Departure[]};
export function useDepartureNotifications(revision:number) {
  const [page,setPage]=useState(1),[data,setData]=useState<Snapshot|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(true);
  useEffect(()=>{
    const controller=new AbortController();setBusy(true);setError('');
    void (async()=>{
      try {
        const response=await fetch(`${api}/api/v1/reservations/departure-notifications?page=${page}`,{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([controller.signal,AbortSignal.timeout(15000)])});
        if(response.status===401){window.location.replace('/login');return;}
        if(!response.ok)throw new Error();
        const snapshot:Snapshot=await response.json();
        if(!controller.signal.aborted)setData(snapshot);
      }catch{if(!controller.signal.aborted){setData(null);setError('Impossible d’actualiser les départs. Utilisez Actualiser pour réessayer.');}}
      finally{if(!controller.signal.aborted)setBusy(false);}
    })();
    return()=>controller.abort();
  },[page,revision]);
  return {data,error,busy,setPage};
}

export default function DepartureNotifications({feed}:{feed:ReturnType<typeof useDepartureNotifications>}) {
  const {data,busy,error,setPage}=feed;
  return <section className="notification-departures" aria-label="Départs à préparer" aria-busy={busy}>
    <h3>Départs à préparer {data&&!busy&&<span>· {data.total}</span>}</h3>
    <p className="notification-intro">Aujourd’hui et demain · les départs non enregistrés restent à vérifier. Heure du Maroc.</p>
    {busy?<p className="notification-message" role="status">Chargement des départs…</p>:error?<p className="notification-message" role="alert">{error}</p>:data?.total===0?<p className="notification-message">Aucun départ confirmé à préparer pour aujourd’hui ou demain.</p>:<ul className="notification-list">{data?.data.map(row=><li key={row.id}><a href={row.href}>
      <span className={'notification-symbol '+row.state}><CarFront size={18}/></span><div>
        <span className="notification-row-top"><strong>{row.vehicle}</strong><span className={'notification-status '+row.state}>{row.state==='overdue'?'Départ à vérifier':row.state==='due'?'Aujourd’hui':'Demain'}</span></span>
        <p>{row.plate} · {row.customer}</p>
        <time className="notification-date" dateTime={row.at}>Départ prévu : {new Intl.DateTimeFormat('fr-FR',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'UTC'}).format(new Date(row.at+'Z'))}</time>
        <p>{row.state==='overdue'?'Horaire dépassé : vérifier le dossier et la remise des clés.':'Prévoir le lavage si nécessaire et vérifier la voiture avant l’arrivée du client.'}</p>
        <small>Ouvrir la préparation du départ <ArrowUpRight size={12}/></small>
      </div></a></li>)}</ul>}
    {data&&!busy&&data.last_page>1&&<div className="notification-pages"><span>{data.page} / {data.last_page}</span><button aria-label="Départs précédents" disabled={data.page<=1} onClick={()=>setPage(data.page-1)}><ChevronLeft size={17}/></button><button aria-label="Départs suivants" disabled={data.page>=data.last_page} onClick={()=>setPage(data.page+1)}><ChevronRight size={17}/></button></div>}
  </section>;
}
