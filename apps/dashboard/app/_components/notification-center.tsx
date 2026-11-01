'use client';

import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {Bell,RefreshCw,X} from 'lucide-react';
import {ActivityList,ActivityPages,activityGroups,activityIcon,useActivityFeed,refreshActivity,type ActivityGroup,type ActivityRow,type ActivityWindow} from './activity-feed';
import ActivityTarget from './activity-target';
import './activity.css';

export default function NotificationCenter({onWebsiteCount}:{onWebsiteCount?:(count:number|null)=>void}){
 const [open,setOpen]=useState(false),[group,setGroup]=useState<ActivityGroup>('all'),[windowChoice,setWindow]=useState<ActivityWindow>('priority'),[page,setPage]=useState(1),[selected,setSelected]=useState<ActivityRow|null>(null);
 const feed=useActivityFeed(group,windowChoice,page,'',!!selected);
 const trigger=useRef<HTMLButtonElement>(null),dialog=useRef<HTMLDialogElement>(null);
 useEffect(()=>{if(!open)return;dialog.current?.showModal();return()=>trigger.current?.focus();},[open]);
 useEffect(()=>{onWebsiteCount?.(feed.error?null:feed.data?.website_request_count??null);},[feed.data,feed.error,onWebsiteCount]);
 const count=feed.data?.priority_count;
 return <>
  <button ref={trigger} className="app-bell" aria-label="Notifications" aria-haspopup="dialog" aria-expanded={open} aria-describedby="notification-badge-description" onClick={()=>{setPage(1);feed.refresh();setOpen(true);}}><Bell size={20}/>{!!count&&<span className="app-bell-badge" aria-hidden="true">{count>99?'99+':count}</span>}{feed.error&&<span className="app-bell-badge" aria-hidden="true">!</span>}</button>
  <span id="notification-badge-description" className="sr-only">{feed.error?'Notifications indisponibles':count===undefined?'Chargement des notifications':`${count} actions à traiter`}</span>
  {open&&createPortal(<dialog ref={dialog} className="notification-dialog activity-notifications" aria-labelledby="notification-title" onCancel={()=>setOpen(false)}><header><div><span className="notification-eyebrow">LE SUIVI DE VOTRE AGENCE</span><h2 id="notification-title">Notifications</h2><p>{count===undefined?'Chargement…':`${count} action${count===1?'':'s'} à traiter`} · Dossiers à suivre, pas des messages non lus</p></div><button className="notification-close" aria-label="Fermer les notifications" onClick={()=>setOpen(false)}><X size={20}/></button></header>
   <div className="notification-categories" aria-label="Type de notification">{([{key:'all',label:'Tout'},{key:'requests',label:'Demandes'},...activityGroups,{key:'finance',label:'Paiements'}] as {key:ActivityGroup;label:string}[]).filter(x=>x.key!=='finance'||feed.data?.can_manage_finance).map(x=>{const Icon=activityIcon(x.key);return <button key={x.key} aria-pressed={group===x.key} onClick={()=>{setGroup(x.key);setPage(1);}}><Icon size={16}/>{x.label}</button>;})}</div>
   <div className="activity-notification-filters"><div className="activity-windows" aria-label="Période des notifications">{([['priority','À traiter'],['upcoming','À venir'],['all','Tout']] as const).map(([key,label])=><button key={key} aria-pressed={windowChoice===key} onClick={()=>{setWindow(key);setPage(1);}}>{label}</button>)}</div><p className="notification-guide">{group==='requests'?'Demandes à reprendre et options à vérifier. Une demande ne bloque pas la voiture.':group==='departures'?'Un départ dépassé doit être vérifié dans le dossier : aucune annulation automatique.':group==='returns'?'Contrôlez le retour réel et la disponibilité pour le prochain client.':group==='deadlines'?'Documents à compléter, échéances et rappels d’entretien.':'Chaque action reste visible jusqu’à la régularisation de son dossier.'}</p></div>
   <div className="notification-body" aria-busy={feed.busy}><ActivityList {...feed} compact onOpen={row=>{setOpen(false);setSelected(row);}}/><ActivityPages data={feed.data} busy={feed.busy} onPage={n=>{setPage(n);dialog.current?.querySelector('.notification-body')?.scrollTo(0,0);}}/></div>
   <footer><a href={'/?'+new URLSearchParams({group,window:windowChoice})}>Tout voir dans Accueil ↗</a><button disabled={feed.busy} onClick={refreshActivity}><RefreshCw size={14}/>Actualiser</button></footer>
  </dialog>,document.body)}
  <ActivityTarget row={selected} today={feed.data?.today??''} canManageFinance={feed.data?.can_manage_finance===true} onClose={()=>{setSelected(null);refreshActivity();trigger.current?.focus();}}/>
 </>;
}
