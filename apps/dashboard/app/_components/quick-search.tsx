'use client';
import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {Search,X,ArrowUpRight} from 'lucide-react';
import {api} from '../_lib/auth-client';
import './quick-search.css';
type Result={id:number;label:string;detail:string;href:string};
type Group={label:string;rows:Result[];error:boolean};
export default function QuickSearch(){
 const [opened,setOpened]=useState(false),[query,setQuery]=useState(''),[groups,setGroups]=useState<Group[]>([]),[busy,setBusy]=useState(false);
 const dialog=useRef<HTMLDialogElement>(null),input=useRef<HTMLInputElement>(null),trigger=useRef<HTMLButtonElement>(null);
 useEffect(()=>{if(!opened)return;dialog.current?.showModal();input.current?.focus();return()=>trigger.current?.focus();},[opened]);
 useEffect(()=>{const shortcut=(e:KeyboardEvent)=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();setOpened(true);}};window.addEventListener('keydown',shortcut);return()=>window.removeEventListener('keydown',shortcut);},[]);
 useEffect(()=>{
  setGroups([]);if(!opened||query.trim().length<2){setBusy(false);return;}
  const controller=new AbortController();setBusy(true);
  const timer=setTimeout(async()=>{
   const q=query.trim();const sources=[{endpoint:'reservations',label:'Dossiers',map:(r:any)=>({id:r.id,label:r.reference,detail:[r.customer.first_name,r.customer.last_name,'·',r.vehicle.plate].join(' '),href:'/reservations?dossier='+r.id})},{endpoint:'customers',label:'Clients',map:(r:any)=>({id:r.id,label:r.first_name+' '+r.last_name,detail:r.phone,href:'/clients?customer='+r.id})},{endpoint:'vehicles',label:'Véhicules',map:(r:any)=>({id:r.id,label:r.make+' '+r.model,detail:r.plate,href:'/vehicles?vehicle='+r.id})}];
   const results=await Promise.all(sources.map(async source=>{try{const response=await fetch(`${api}/api/v1/${source.endpoint}?`+new URLSearchParams({q,page:'1'}),{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([controller.signal,AbortSignal.timeout(10000)])});if(response.status===401){window.location.replace('/login');throw Error();}if(!response.ok)throw Error();const body=await response.json();return {label:source.label,rows:body.data.slice(0,5).map(source.map),error:false};}catch{return {label:source.label,rows:[],error:true};}}));
   if(!controller.signal.aborted){setGroups(results);setBusy(false);}
  },300);return()=>{clearTimeout(timer);controller.abort();};
 },[query,opened]);
 return <><button ref={trigger} type="button" className="quick-search-trigger" onClick={()=>setOpened(true)} aria-label="Rechercher un dossier, un client ou une voiture"><Search size={18}/><span>Rechercher</span><kbd>Ctrl K</kbd></button>{opened&&createPortal(<dialog className="quick-search-dialog" ref={dialog} aria-labelledby="quick-search-title" onCancel={()=>setOpened(false)} onKeyDownCapture={e=>{if(e.key==='Escape'){e.preventDefault();e.stopPropagation();setOpened(false);}}}><header><div><h2 id="quick-search-title">Trouver un dossier, un client ou une voiture</h2><p>Nom, téléphone, plaque ou numéro de dossier.</p></div><button type="button" onClick={()=>setOpened(false)} aria-label="Fermer la recherche"><X size={20}/></button></header><label className="quick-search-input"><Search size={20}/><span className="sr-only">Recherche rapide</span><input ref={input} type="search" value={query} maxLength={100} onChange={e=>setQuery(e.target.value)} placeholder="Ex. Karim, DEM-900031 ou une plaque…" autoComplete="off"/></label><div className="quick-search-results" aria-busy={busy}>{query.trim().length<2?<p>Saisissez au moins 2 caractères.</p>:busy?<p role="status">Recherche en cours…</p>:groups.map(group=><section key={group.label}><h3>{group.label}<small>5 résultats maximum</small></h3>{group.error?<p role="alert">Recherche indisponible pour cette catégorie. Modifiez la recherche pour réessayer.</p>:!group.rows.length?<p>Aucun résultat.</p>:<ul>{group.rows.map(row=><li key={row.id}><a href={row.href}><span><strong>{row.label}</strong><small>{row.detail}</small></span><ArrowUpRight size={16}/></a></li>)}</ul>}</section>)}</div><footer>Échap pour fermer · cliquez sur un résultat pour l’ouvrir.</footer></dialog>,document.body)}</>;
}
