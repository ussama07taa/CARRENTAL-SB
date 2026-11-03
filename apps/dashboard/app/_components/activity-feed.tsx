'use client';

import {useCallback, useEffect, useState} from 'react';
import {ArrowDownLeft, ArrowUpRight, CalendarClock, Check, ChevronLeft, ChevronRight, ClipboardList, FileClock, RefreshCw, Wallet, Wrench} from 'lucide-react';
import {api} from '../_lib/auth-client';
import {moroccoDateTimeFormat} from '../_lib/morocco-time';

export type ActivityGroup='all'|'departures'|'returns'|'fleet'|'deadlines'|'requests'|'finance';
export type ActivityWindow='priority'|'upcoming'|'all'|'urgent'|'action';
export type ActivityRow={key:string;group:ActivityGroup;title:string;subject:string;context:string;detail:string;date:string|null;date_label:string;date_note?:string;priority:'overdue'|'today'|'action'|'upcoming';status:string;action:string;href:string|null;vehicle:{id:number;make:string;model:string;plate:string}|null;amount_centimes?:number;amount_label?:string;target:{type:'documents'|'maintenance'|'loan';id?:number;vehicle_id?:number;entry_id?:number|null;kind?:string;operation?:'pay'|'return'|'ready'|'price'|null;followup_key?:string|null;odometer?:{value:number|null;date:string|null}|null;installment_id?:number|null;purchase?:boolean}|null};
export type DayOverview={departures_today:number;returns_today:number;overdue_returns:number;pending_requests:number;urgent_count:number;urgent:ActivityRow[];schedule_count:number;schedule:ActivityRow[]};
export type ActivityData={notification_counts?:{urgent:number;action:number;upcoming:number}|null;overview?:DayOverview;data:ActivityRow[];total:number;page:number;last_page:number;counts:Partial<Record<ActivityGroup,number>>;priority_count:number;website_request_count:number;can_manage_finance:boolean;today:string;generated_at:string};
export const activityGroups:{key:ActivityGroup;label:string;detail:string;icon:typeof ArrowUpRight}[]=[
 {key:'departures',label:'Départs',detail:'Aujourd’hui, demain et à vérifier',icon:ArrowUpRight},
 {key:'returns',label:'Retours',detail:'Attendus d’ici demain et en retard',icon:ArrowDownLeft},
 {key:'fleet',label:'Préparation & garage',detail:'Actions de garage et de contrôle',icon:Wrench},
 {key:'deadlines',label:'Documents & entretien',detail:'À compléter ou sous 30 jours',icon:FileClock},
];
export function activityIcon(group:ActivityGroup){return activityGroups.find(x=>x.key===group)?.icon??(group==='finance'?Wallet:ClipboardList);}
export function activityDate(value:string|null){
 if(!value)return 'Date non renseignée';
 const hasTime=value.includes('T');
 const options:Intl.DateTimeFormatOptions={day:'numeric',month:'short',year:'numeric',...(hasTime?{hour:'2-digit',minute:'2-digit'}:{timeZone:'UTC'})};
 return (hasTime?moroccoDateTimeFormat('fr-FR',options):new Intl.DateTimeFormat('fr-FR',options)).format(new Date(hasTime?value:value+'T12:00:00Z'));
}
export function refreshActivity(){window.dispatchEvent(new Event('agency-activity-changed'));}
export function useActivityFeed(group:ActivityGroup,windowChoice:ActivityWindow,page:number,q='',paused=false,notifications=false){
 const [data,setData]=useState<ActivityData|null>(null),[busy,setBusy]=useState(true),[error,setError]=useState(''),[revision,setRevision]=useState(0);
 const refresh=useCallback(()=>setRevision(v=>v+1),[]);
 useEffect(()=>{
  const abort=new AbortController();setBusy(true);setError('');
  void(async()=>{try{
   const r=await fetch(api+'/api/v1/dashboard/activity?'+new URLSearchParams({group,window:windowChoice,page:String(page),q,...(notifications?{notifications:'1'}:{})}),{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([abort.signal,AbortSignal.timeout(20000)])});
   if(r.status===401){window.location.replace('/login');return;}
   if(!r.ok)throw Error(r.status===403?'Ce suivi n’est pas accessible avec votre compte.':'Impossible d’actualiser les actions. Réessayez.');
   const body:ActivityData=await r.json();if(!abort.signal.aborted)setData(body);
  }catch(e){if(!abort.signal.aborted){setData(null);setError(e instanceof Error?e.message:'Connexion impossible.');}}
  finally{if(!abort.signal.aborted)setBusy(false);}})();
  return()=>abort.abort();
 },[group,windowChoice,page,q,revision,notifications]);
 useEffect(()=>{
  const visible=()=>{if(document.visibilityState==='visible'&&!paused)refresh();};
  const timer=setInterval(visible,60000);
  document.addEventListener('visibilitychange',visible);window.addEventListener('focus',visible);window.addEventListener('agency-activity-changed',refresh);
  return()=>{clearInterval(timer);document.removeEventListener('visibilitychange',visible);window.removeEventListener('focus',visible);window.removeEventListener('agency-activity-changed',refresh);};
 },[paused,refresh]);
 return {data,busy,error,refresh};
}
export function ActivityList({data,busy,error,refresh,onOpen,compact=false}:{data:ActivityData|null;busy:boolean;error:string;refresh:()=>void;onOpen:(row:ActivityRow)=>void;compact?:boolean}){
 if(busy&&!data)return <div className="activity-message" role="status"><RefreshCw size={21}/><span>Actualisation des actions…</span></div>;
 if(error)return <div className="activity-message activity-error" role="alert"><p>{error}</p><button className="secondary" onClick={refresh}>Réessayer</button></div>;
 if(!data)return null;
 if(!data.data.length)return <div className="activity-message activity-empty"><Check size={25}/><strong>Aucune action dans cette vue.</strong><p>Consultez « À venir » pour anticiper les prochaines échéances.</p></div>;
 return <ul className={'activity-list'+(compact?' is-compact':'')}>{data.data.map(row=>{const Icon=activityIcon(row.group);
 const lateDeparture=row.group==='departures'&&row.priority==='overdue';
 const day=row.date?new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Casablanca',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(row.date.includes('T')?row.date:row.date+'T12:00:00Z')):'';
 const days=day?Math.max(0,Math.round((Date.parse(data.today+'T12:00:00Z')-Date.parse(day+'T12:00:00Z'))/86400000)):0;
 const title=lateDeparture?'Départ dépassé — vérifier le dossier':row.title;
 const action=lateDeparture?'Vérifier le dossier':row.action;
 return <li key={row.key} data-activity-key={row.key}>
  <span className={'activity-symbol '+row.group}><Icon size={20}/></span>
  <div className="activity-copy"><div className="activity-row-heading"><h3>{title}</h3><span className={'activity-status '+row.priority}>{lateDeparture?'Départ non enregistré':row.status}</span></div>
   <strong className="activity-subject">{row.subject}</strong>{row.context&&<span className="activity-context">{row.context}</span>}

   <p>{lateDeparture?'La remise des clés n’est pas enregistrée. Vérifiez avec le client si le départ a eu lieu, a été reporté ou doit être annulé.':row.detail}</p>
   {row.priority==='overdue'&&days>0&&<strong className="activity-delay">Échéance dépassée de {days} jour{days>1?'s':''}</strong>}{(!compact||row.date||row.date_note)&&<div className="activity-date"><CalendarClock size={14}/>{row.date?<time dateTime={row.date}>{row.date_label} : {activityDate(row.date)}</time>:<span>{row.date_note??'Date à renseigner dans le dossier'}</span>}</div>}
   {data.can_manage_finance&&row.amount_centimes!==undefined&&<span className="activity-amount">{row.amount_label??'À vérifier'} : <strong>{new Intl.NumberFormat('fr-MA',{minimumFractionDigits:2,maximumFractionDigits:2}).format(row.amount_centimes/100)} DH</strong></span>}
  </div>
  {row.href?<a className="activity-action" href={row.href}>{action}<ArrowUpRight size={15}/></a>:<button className="activity-action" onClick={()=>onOpen(row)}>{action}<ArrowUpRight size={15}/></button>}
 </li>;})}</ul>;
}
export function ActivityPages({data,busy,onPage}:{data:ActivityData|null;busy:boolean;onPage:(page:number)=>void}){
 if(!data||data.last_page<=1)return null;
 return <nav className="activity-pages" aria-label="Pages du suivi"><span>{data.total} actions · {data.page} / {data.last_page}</span><div><button disabled={busy||data.page<=1} aria-label="Actions précédentes" onClick={()=>onPage(data.page-1)}><ChevronLeft size={18}/></button><button disabled={busy||data.page>=data.last_page} aria-label="Actions suivantes" onClick={()=>onPage(data.page+1)}><ChevronRight size={18}/></button></div></nav>;
}
