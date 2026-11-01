'use client';

import {useEffect,useRef,useState} from 'react';
import {ArrowUpRight,CalendarDays,CarFront,ChartNoAxesCombined,ClipboardList,RefreshCw,Search,Wallet,Wrench,X,Plus,ArrowDownLeft,Clock3,CheckCircle2} from 'lucide-react';
import type {Session} from '../_lib/session';
import {moroccoDateTimeFormat} from '../_lib/morocco-time';
import DamageFollowups from '../incidents/followups';
import {ActivityList,ActivityPages,activityGroups,useActivityFeed,refreshActivity,type ActivityGroup,type ActivityRow,type ActivityWindow} from './activity-feed';
import ActivityTarget from './activity-target';
import './activity.css';
import './dashboard-day.css';

const labels:Record<ActivityGroup,string>={all:'Toutes les actions',departures:'Départs à préparer',returns:'Retours à suivre',fleet:'Préparation & garage',deadlines:'Documents & entretien',requests:'Demandes à reprendre',finance:'Paiements & comptes clients'};
export default function Cockpit({session}:{session:Session}){
 const [group,setGroup]=useState<ActivityGroup>('all'),[windowChoice,setWindow]=useState<ActivityWindow>('priority'),[page,setPage]=useState(1),[q,setQ]=useState(''),[search,setSearch]=useState(''),[initialized,setInitialized]=useState(false);
 const [dayTab,setDayTab]=useState<'all'|'departures'|'returns'>('all');
 const [selected,setSelected]=useState<ActivityRow|null>(null);
 const list=useRef<HTMLElement>(null);
 const feed=useActivityFeed(group,windowChoice,page,search,!!selected);
 useEffect(()=>{
  const read=()=>{const p=new URLSearchParams(location.search);const g=p.get('group')??(p.get('tab')==='documents'?'deadlines':p.get('tab')==='credit'?'finance':'all');setGroup(Object.hasOwn(labels,g)?g as ActivityGroup:'all');const w=p.get('window');setWindow(w==='priority'||w==='all'||w==='upcoming'?w:p.get('q')?'all':'priority');setQ((p.get('q')??'').slice(0,100));setSearch((p.get('q')??'').slice(0,100));setPage(Math.max(1,Number(p.get('page'))||1));setInitialized(true);};read();window.addEventListener('popstate',read);return()=>window.removeEventListener('popstate',read);
 },[]);
 useEffect(()=>{const timer=setTimeout(()=>setSearch(q.trim()),250);return()=>clearTimeout(timer);},[q]);
 useEffect(()=>{if(!initialized)return;const p=new URLSearchParams();if(group!=='all')p.set('group',group);if(windowChoice!=='priority'||search)p.set('window',windowChoice);if(search)p.set('q',search);if(page>1)p.set('page',String(page));window.history.replaceState(window.history.state,'','/'+(p.size?'?'+p:''));},[initialized,group,windowChoice,search,page]);
 function choose(next:ActivityGroup){setGroup(next);setPage(1);setQ('');setSearch('');list.current?.scrollIntoView({block:'start'});}
 function close(){setSelected(null);refreshActivity();}
 const data=feed.data;
 const finance=session.can_manage_expenses===true&&data?.can_manage_finance===true;
 return <section className="activity-home dashboard-day" aria-label="Votre journée à l’agence">
  <header className="activity-home-heading"><div><span className="activity-eyebrow"><CalendarDays size={16}/>{data?new Intl.DateTimeFormat('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(data.today+'T12:00:00Z')):'VOTRE AGENCE'}</span><h1>Tableau de bord</h1><p>Votre journée, vos priorités et les dossiers à suivre.</p></div><div className="activity-heading-actions"><a className="primary" href="/reservations?new=1"><Plus size={18}/>Nouvelle réservation</a><button className="secondary" aria-label="Actualiser l’accueil" disabled={feed.busy} onClick={refreshActivity}><RefreshCw size={18}/></button></div></header>
  <div className="day-metrics" aria-label="Résumé de la journée">
   {[{label:'Départs aujourd’hui',value:data?.overview?.departures_today,detail:'Confirmés · à remettre',Icon:ArrowUpRight},
     {label:'Retours aujourd’hui',value:data?.overview?.returns_today,detail:'Voitures encore en location',Icon:ArrowDownLeft},
     {label:'Retours en retard',value:data?.overview?.overdue_returns,detail:'Heure prévue dépassée',Icon:Clock3},
     {label:'Demandes en attente',value:data?.overview?.pending_requests,detail:'Toutes dates · hors options',Icon:ClipboardList}].map(({label,value,detail,Icon},i)=><article key={label} className={i===2&&!!value?'day-metric is-alert':'day-metric'}><span className="day-metric-icon"><Icon size={21}/></span><span>{label}</span><strong>{value??'—'}</strong><small>{detail}</small></article>)}
  </div>
  {feed.error&&<p className="notice error" role="alert">{feed.error}</p>}
  <div className="day-work-grid">
   <section className="day-panel day-urgent" aria-labelledby="day-urgent-title"><header><div><span className="activity-eyebrow">LES PRIORITÉS</span><h2 id="day-urgent-title">À traiter maintenant <span>{data?.overview?.urgent_count??'—'}</span></h2></div><Clock3 size={22} aria-hidden="true"/></header><p className="day-panel-intro">Retards et échéances du jour. Départs et retours en premier.</p>
    {!data?<p className="day-empty" role="status">{feed.error?'Données indisponibles.':'Chargement des priorités…'}</p>:!data.overview?.urgent.length?<div className="day-empty"><CheckCircle2 size={25}/><strong>Aucun retard ni échéance aujourd’hui.</strong><span>Consultez le suivi pour les autres dossiers à compléter.</span></div>:<ul className="day-urgent-list">{data.overview.urgent.map(row=><li key={row.key}><div><span className={'activity-status '+row.priority}>{row.status}</span><h3>{row.title}</h3><strong>{row.subject}</strong><p>{row.context}</p></div>{row.href?<a href={row.href}>{row.action}<ArrowUpRight size={16}/></a>:<button type="button" onClick={()=>setSelected(row)}>{row.action}<ArrowUpRight size={16}/></button>}</li>)}</ul>}
    <button type="button" className="day-text-link" onClick={()=>{setWindow('priority');choose('all');}}>Ouvrir le suivi des actions <ArrowUpRight size={16}/></button>
   </section>
   <div className="day-main-column">
   <section className="day-panel day-programme" aria-labelledby="day-programme-title"><header><div><span className="activity-eyebrow">HEURE DU MAROC</span><h2 id="day-programme-title">Programme du jour <span>{data?.overview?.schedule_count??'—'}</span></h2></div><a href="/reservations?view=planning" className="day-text-link">Planning <ArrowUpRight size={16}/></a></header>
    <div className="day-tabs" aria-label="Programme du jour">{([['all','Tout'],['departures','Départs'],['returns','Retours']] as const).map(([key,label])=><button key={key} type="button" aria-pressed={dayTab===key} onClick={()=>setDayTab(key)}>{label}</button>)}</div>
    {!data?<p className="day-empty" role="status">{feed.error?'Données indisponibles.':'Chargement du programme…'}</p>:!data.overview?.schedule.filter(row=>dayTab==='all'||row.group===dayTab).length?<div className="day-empty"><CalendarDays size={26}/><strong>Aucune opération à suivre dans cette vue.</strong><span>Les départs confirmés et les retours encore attendus apparaissent ici.</span></div>:<ol className="day-timeline">{data.overview.schedule.filter(row=>dayTab==='all'||row.group===dayTab).map(row=><li key={row.key}><time dateTime={row.date??undefined}>{row.date?moroccoDateTimeFormat('fr-FR',{hour:'2-digit',minute:'2-digit'}).format(new Date(row.date)):'—'}</time><div><span className="day-direction">{row.group==='departures'?'Départ':'Retour'}</span><strong>{row.subject}</strong><p>{row.context}</p>{row.priority==='overdue'&&<span className="activity-status overdue">{row.status}</span>}</div><a href={row.href??'/reservations'} aria-label={'Ouvrir : '+row.context}><ArrowUpRight size={19}/></a></li>)}</ol>}
    {!!data?.overview&&data.overview.schedule_count>data.overview.schedule.length&&<a className="day-text-link" href="/reservations?view=planning">Voir le programme complet dans le planning →</a>}
   </section>
  <section ref={list} className="activity-board" id="suivi" aria-labelledby="activity-board-title">
   <header className="activity-board-heading"><div><span className="activity-eyebrow">SUIVI DE L’AGENCE</span><h2 id="activity-board-title">{labels[group]} <span>{data?.total??'—'}</span>{feed.busy&&<small className="day-refresh" role="status">Actualisation…</small>}</h2></div><div className="activity-windows" aria-label="Période des actions">{([['priority','À traiter'],['upcoming','À venir'],['all','Tout']] as const).map(([key,label])=><button key={key} aria-pressed={windowChoice===key} onClick={()=>{setWindow(key);setPage(1);}}>{label}</button>)}</div></header>
   <div className="activity-toolbar"><nav aria-label="Catégories des actions">{(['all','departures','returns','fleet','deadlines','requests',...(finance?['finance']:[])] as ActivityGroup[]).map(key=><button key={key} aria-pressed={group===key} onClick={()=>choose(key)}>{({all:'Tout',departures:'Départs',returns:'Retours',fleet:'Garage & préparation',deadlines:'Documents & entretien',requests:'Demandes',finance:'Paiements'} as const)[key]}</button>)}</nav><label className="activity-search"><Search size={17}/><input data-validation-field="q" aria-label="Rechercher une action" type="search" maxLength={100} placeholder="Voiture, client, document…" value={q} onChange={e=>{setQ(e.target.value);setPage(1);}}/></label></div>
   <p className="activity-scope">{windowChoice==='priority'?'Retards, échéances du jour et dossiers à compléter.':windowChoice==='upcoming'?'Départs et retours de demain · autres échéances sous 30 jours.':'Retards en premier · départs et retours jusqu’à demain · autres échéances sous 30 jours.'} Heure du Maroc.</p>
   <ActivityList {...feed} busy={feed.busy||q.trim()!==search} onOpen={setSelected}/>
   <ActivityPages data={data} busy={feed.busy||q.trim()!==search} onPage={n=>{setPage(n);list.current?.scrollIntoView({block:'start'});}}/>
  </section>
   </div>
  </div>
  <div className="activity-bottom-links"><a href="/reservations?view=planning"><CalendarDays size={18}/><span>Planning des voitures</span><ArrowUpRight size={16}/></a><a href="/maintenance"><Wrench size={18}/><span>Entretien & garage</span><ArrowUpRight size={16}/></a>{finance&&<><a href="/cash"><Wallet size={18}/><span>Ouvrir la caisse</span><ArrowUpRight size={16}/></a><a href="/reports"><ChartNoAxesCombined size={18}/><span>Consulter les rapports</span><ArrowUpRight size={16}/></a></>}</div>
  <details className="day-incidents"><summary>Sinistres et règlements à suivre</summary><DamageFollowups/></details>
  <p className="activity-updated">{data?'Actualisé à '+moroccoDateTimeFormat('fr-FR',{hour:'2-digit',minute:'2-digit'}).format(new Date(data.generated_at)):'En attente du serveur'} · Les actions disparaissent lorsque leur situation est réglée dans le dossier.</p>
  <ActivityTarget row={selected} today={data?.today??''} canManageFinance={finance} onClose={close}/>
 </section>;
}
