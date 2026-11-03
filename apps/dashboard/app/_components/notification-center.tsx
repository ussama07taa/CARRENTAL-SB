'use client';

import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {Bell,RefreshCw,X} from 'lucide-react';
import {ActivityList,ActivityPages,activityGroups,useActivityFeed,refreshActivity,type ActivityGroup,type ActivityRow,type ActivityWindow} from './activity-feed';
import ActivityTarget from './activity-target';
import './activity.css';

export default function NotificationCenter({onWebsiteCount}:{onWebsiteCount?:(count:number|null)=>void}){
 const [open,setOpen]=useState(false),[group,setGroup]=useState<ActivityGroup>('all'),[windowChoice,setWindow]=useState<ActivityWindow>('action'),[page,setPage]=useState(1),[selected,setSelected]=useState<ActivityRow|null>(null);
 const feed=useActivityFeed(group,windowChoice,page,'',!!selected,true);
 const trigger=useRef<HTMLButtonElement>(null),dialog=useRef<HTMLDialogElement>(null);
 useEffect(()=>{if(!open)return;dialog.current?.showModal();return()=>trigger.current?.focus();},[open]);
 useEffect(()=>{onWebsiteCount?.(feed.error?null:feed.data?.website_request_count??null);},[feed.data,feed.error,onWebsiteCount]);
 const count=feed.data?.priority_count;
 return <>
  <button ref={trigger} className="app-bell" aria-label="Notifications" aria-haspopup="dialog" aria-expanded={open} aria-describedby="notification-badge-description" onClick={()=>{setPage(1);feed.refresh();setOpen(true);}}><Bell size={20}/>{!!count&&<span className="app-bell-badge" aria-hidden="true">{count>99?'99+':count}</span>}{feed.error&&<span className="app-bell-badge" aria-hidden="true">!</span>}</button>
  <span id="notification-badge-description" className="sr-only">{feed.error?'Notifications indisponibles':count===undefined?'Chargement des notifications':`${count} urgences et demandes à traiter`}</span>
  {open&&createPortal(<dialog ref={dialog} className="notification-dialog activity-notifications" aria-labelledby="notification-title" onCancel={()=>setOpen(false)}><header><div><span className="notification-eyebrow">LE SUIVI DE VOTRE AGENCE</span><h2 id="notification-title">Notifications</h2><p>Vos urgences, dossiers à traiter et prochaines échéances.</p></div><button className="notification-close" aria-label="Fermer les notifications" onClick={()=>setOpen(false)}><X size={20}/></button></header>
   <div className="activity-notification-filters">
    <div className="activity-windows" aria-label="Priorité des notifications">{([['urgent','Urgent'],['action','À traiter'],['upcoming','À venir']] as const).map(([key,label])=><button key={key} aria-pressed={windowChoice===key} onClick={()=>{setWindow(key);setPage(1);}}>{label}<span className="notification-count">{feed.data?.notification_counts?.[key]??'—'}</span></button>)}</div>
    <label className="notification-type-filter">Type<select value={group} onChange={e=>{setGroup(e.target.value as ActivityGroup);setPage(1);}}>{([{key:'all',label:'Toutes les actions'},{key:'requests',label:'Demandes'},...activityGroups,{key:'finance',label:'Paiements'}] as {key:ActivityGroup;label:string}[]).filter(x=>x.key!=='finance'||feed.data?.can_manage_finance).map(x=><option key={x.key} value={x.key}>{x.label}</option>)}</select></label>
    <p className="notification-guide">{windowChoice==='urgent'?'Retards et échéances du jour à vérifier en premier.':windowChoice==='action'?'Demandes et dossiers à compléter. Documents regroupés par voiture.':'Échéances à anticiper et prochains départs ou retours.'}</p>
   </div>
   <div className="notification-body" aria-busy={feed.busy}><ActivityList {...feed} compact onOpen={row=>{setOpen(false);setSelected(row);}}/><ActivityPages data={feed.data} busy={feed.busy} onPage={n=>{setPage(n);dialog.current?.querySelector('.notification-body')?.scrollTo(0,0);}}/></div>
   <footer><a href={'/?'+new URLSearchParams({group,window:windowChoice==='upcoming'?'upcoming':'priority'})}>Tout voir dans Accueil ↗</a><button disabled={feed.busy} onClick={refreshActivity}><RefreshCw size={14}/>Actualiser</button></footer>
  </dialog>,document.body)}
  <ActivityTarget row={selected} today={feed.data?.today??''} canManageFinance={feed.data?.can_manage_finance===true} onClose={()=>{setSelected(null);refreshActivity();trigger.current?.focus();}}/>
 </>;
}
