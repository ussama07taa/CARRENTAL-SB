'use client';

import {useEffect,useRef,useState} from 'react';
import {api,apiMutation} from '../_lib/auth-client';

type Person={id:number;first_name:string;last_name:string;phone:string;identity_number?:string|null;licence_number?:string|null;licence_expires_on?:string|null};
type Data={ready:boolean;editable:boolean;revision:number|null;customer:Person|null;fingerprint:string;verified:boolean;missing:string[]};
type Props={id:number;disabled:boolean;onSaved:()=>void;onBusyChange:(v:boolean)=>void;onDirtyChange:(v:boolean)=>void};
const emptyPerson={first_name:'',last_name:'',phone:'',identity_number:'',licence_number:'',licence_expires_on:''};

export default function AdditionalDriver({id,disabled,onSaved,onBusyChange,onDirtyChange}:Props){
 const [data,setData]=useState<Data|null>(null),[refresh,setRefresh]=useState(0),[error,setError]=useState(''),[notice,setNotice]=useState('');
 const [mode,setMode]=useState(''),[query,setQuery]=useState(''),[people,setPeople]=useState<Person[]>([]),[selected,setSelected]=useState<Person|null>(null);
 const [reason,setReason]=useState(''),[identity,setIdentity]=useState(false),[licence,setLicence]=useState(false),[busy,setBusy]=useState(false),[uncertain,setUncertain]=useState(false),[searching,setSearching]=useState(false);
 const lock=useRef(false),payload=useRef<Record<string,unknown>|null>(null);
 const [newPerson,setNewPerson]=useState(emptyPerson),[fieldErrors,setFieldErrors]=useState<Record<string,string[]>>({}),[duplicate,setDuplicate]=useState(''),[acknowledge,setAcknowledge]=useState(false);
 useEffect(()=>{onDirtyChange(Boolean(mode));return()=>onDirtyChange(false);},[mode,onDirtyChange]);
 useEffect(()=>{
  const ctl=new AbortController();
  fetch(`${api}/api/v1/reservations/${id}/drivers`,{credentials:'include',cache:'no-store',signal:ctl.signal}).then(async r=>{const b=await r.json();if(!r.ok)throw Error(b.message||'Chargement impossible.');setData(b.data);}).catch(e=>{if(!ctl.signal.aborted)setError(e.message);});
  return()=>ctl.abort();
 },[id,refresh]);
 useEffect(()=>{
  setPeople([]);if(mode!=='assigned'||query.trim().length<2){setSearching(false);return;}
  const ctl=new AbortController();setSearching(true);
  const timer=setTimeout(()=>{fetch(`${api}/api/v1/customers?q=${encodeURIComponent(query.trim())}`,{credentials:'include',cache:'no-store',signal:ctl.signal}).then(async r=>{const b=await r.json();if(!r.ok)throw Error(b.message||'Recherche indisponible.');setPeople(b.data);}).catch(e=>{if(!ctl.signal.aborted)setError(e.message);}).finally(()=>{if(!ctl.signal.aborted)setSearching(false);});},250);
  return()=>{clearTimeout(timer);ctl.abort();};
 },[query,mode]);
 function open(action:string){setMode(action);setReason('');setSelected(null);setQuery('');setIdentity(false);setLicence(false);setError('');setNotice('');setFieldErrors({});setDuplicate('');setAcknowledge(false);payload.current=null;}
 async function save(){
  if(lock.current||!data)return;
  if(!uncertain){
   if(reason.trim().length<3){setError('Précisez le motif (au moins 3 caractères).');return;}
   if(mode==='assigned'&&!selected){setError('Choisissez une personne.');return;}
   if(mode==='verified'&&(!identity||!licence)){setError('Contrôlez les deux originaux avant de confirmer.');return;}
   payload.current={action:mode,expected_revision:data.revision,reason:reason.trim(),request_key:crypto.randomUUID(),...(mode==='assigned'?{customer_id:selected!.id}:{}),...(mode==='verified'?{fingerprint:data.fingerprint,identity_original:true,licence_original:true}:{})};
   if(mode==='new')payload.current={...newPerson,identity_type:'cin',language:'fr',expected_revision:data.revision,reason:reason.trim(),creation_key:crypto.randomUUID(),acknowledge_duplicates:acknowledge};
  }
  lock.current=true;setBusy(true);onBusyChange(true);setError('');
  try{
   const r=await apiMutation(`reservations/${id}/drivers${mode==='new'?'/new':''}`,'POST',payload.current);const b=await r.json();
   if(!r.ok){
    if(r.status>=500){setUncertain(true);throw Error('Réponse incertaine. Réessayez la même opération.');}
    setUncertain(false);payload.current=null;
    setFieldErrors(b.errors??{});setDuplicate(b.code??'');
    if(r.status===409&&!['IDENTITY_ALREADY_USED','POSSIBLE_DUPLICATE'].includes(b.code)){setMode('');setRefresh(n=>n+1);}
    throw Error(Object.values(b.errors??{}).flat().join(' ')||b.message||'Enregistrement impossible.');
   }
   setData(b.data);setUncertain(false);payload.current=null;setMode('');setNewPerson(emptyPerson);setFieldErrors({});setDuplicate('');setNotice('Conducteur enregistré. Contrôlez ses originaux, puis préparez la nouvelle version du contrat.');onSaved();
  }catch(e){
   if(e instanceof TypeError||(e instanceof DOMException&&['TimeoutError','AbortError'].includes(e.name))){setUncertain(true);setError('Réponse incertaine. Réessayez la même opération sans créer un doublon.');}
   else setError(e instanceof Error?e.message:'Enregistrement impossible.');
  }finally{lock.current=false;setBusy(false);onBusyChange(false);}
 }
 return <section className="rq-panel" aria-label="Conducteur supplémentaire">
  <div className="dp-title"><h4>Conducteur supplémentaire</h4>{data?.customer&&<span className={data.verified?'dp-ok':'dp-pending'}>{data.verified?'Originaux vérifiés':'À vérifier'}</span>}</div>
  {error&&<p role="alert" className="dp-warning">{error}</p>}
  {notice&&<p role="status">{notice}</p>}
  {!data?<button type="button" className="secondary" onClick={()=>setRefresh(n=>n+1)}>Actualiser</button>:!data.ready?<p>Cette fonction attend l’activation de la mise à jour.</p>:<>
   {data.customer?<dl className="dp-doc-values">
    <div><dt>Conducteur</dt><dd>{data.customer.first_name} {data.customer.last_name}</dd></div>
    <div><dt>Identité</dt><dd>{data.customer.identity_number||'À compléter'}</dd></div>
    <div><dt>Permis</dt><dd>{data.customer.licence_number||'À compléter'}</dd></div>
    <div><dt>Valable jusqu’au</dt><dd>{data.customer.licence_expires_on?.slice(0,10)||'À compléter'}</dd></div>
   </dl>:<p>Aucun conducteur supplémentaire. Ajoutez-en un uniquement si une autre personne doit conduire.</p>}
   {data.missing.map(m=><p key={m} className="dp-warning">{m}</p>)}
   {!mode&&data.editable&&<div className="dp-actions">
    {data.customer?<><button type="button" className="primary" disabled={disabled||busy||data.missing.length>0} onClick={()=>open('verified')}>{data.verified?'Vérifier à nouveau les originaux':'Vérifier les originaux du conducteur'}</button><button type="button" className="secondary" disabled={disabled||busy} onClick={()=>open('removed')}>Retirer ce conducteur</button></>:<><button type="button" className="primary" disabled={disabled||busy} onClick={()=>open('new')}>Ajouter un nouveau conducteur</button><button type="button" className="secondary" disabled={disabled||busy} onClick={()=>open('assigned')}>Rechercher une personne existante</button></>}
   </div>}
   {mode&&<form onSubmit={e=>{e.preventDefault();void save();}}>
    <fieldset disabled={busy||disabled||uncertain} className="dp-form">
     <legend>{mode==='new'?'Nouveau conducteur · dans ce dossier':mode==='assigned'?'Choisir le conducteur':mode==='verified'?'Contrôler les originaux du conducteur':'Retirer le conducteur avant le départ'}</legend>
     {mode==='new'&&<>
      <p>Renseignez la personne qui conduira aussi la voiture. Son fichier sera créé et lié à ce dossier en un seul enregistrement.</p>
      {(Object.entries({first_name:'Prénom',last_name:'Nom',phone:'Téléphone',identity_number:'Numéro de CIN',licence_number:'Numéro de permis',licence_expires_on:'Permis valable jusqu’au'}) as [keyof typeof emptyPerson,string][]).map(([key,label])=><label key={key}>{label}<input type={key==='licence_expires_on'?'date':key==='phone'?'tel':'text'} value={newPerson[key]} required maxLength={key==='first_name'||key==='last_name'?100:50} aria-invalid={Boolean(fieldErrors[key]?.length)} aria-describedby={fieldErrors[key]?.length?`driver-${id}-${key}-error`:undefined} onChange={e=>{setNewPerson(v=>({...v,[key]:e.target.value}));setFieldErrors(v=>({...v,[key]:[]}));setAcknowledge(false);setDuplicate('');}}/>{fieldErrors[key]?.length>0&&<span id={`driver-${id}-${key}-error`} role="alert">{fieldErrors[key].join(' ')}</span>}</label>)}
      {duplicate==='POSSIBLE_DUPLICATE'&&<label className="dp-check"><input type="checkbox" checked={acknowledge} onChange={e=>setAcknowledge(e.target.checked)}/>J’ai vérifié : malgré le nom ou téléphone commun, il s’agit d’une autre personne.</label>}
      {duplicate&&<button type="button" className="secondary" onClick={()=>{const q=duplicate==='IDENTITY_ALREADY_USED'?newPerson.identity_number:newPerson.phone;open('assigned');setQuery(q);}}>Rechercher la personne déjà enregistrée</button>}
     </>}
     {mode==='assigned'&&<>
      <label>Rechercher par nom, téléphone ou CIN<input value={query} onChange={e=>{setQuery(e.target.value);setSelected(null);}} autoComplete="off"/></label>
      {searching&&<p role="status">Recherche…</p>}
      {!searching&&query.trim().length>=2&&!people.length&&<p>Aucune personne trouvée.</p>}
      {people.map(p=><label className="dp-check" key={p.id}><input type="radio" name={`driver-${id}`} checked={selected?.id===p.id} onChange={()=>setSelected(p)}/>{p.first_name} {p.last_name} · {p.phone}</label>)}
      <button type="button" className="secondary" onClick={()=>open('new')}>Personne introuvable ? Ajouter un nouveau conducteur</button>
     </>}
     {mode==='verified'&&<><label className="dp-check"><input type="checkbox" checked={identity} onChange={e=>setIdentity(e.target.checked)}/>J’ai contrôlé l’original de l’identité de ce conducteur.</label><label className="dp-check"><input type="checkbox" checked={licence} onChange={e=>setLicence(e.target.checked)}/>J’ai contrôlé l’original et la validité de son permis pour ce départ.</label></>}
     <label>Motif<textarea value={reason} maxLength={1000} onChange={e=>setReason(e.target.value)} required minLength={3}/></label>
     <p>Un changement nécessite une nouvelle version du contrat et sa signature. Les versions précédentes restent conservées.</p>
    </fieldset>
    <div className="dp-actions"><button type="submit" className="primary" disabled={busy||disabled}>{busy?'Enregistrement…':uncertain?'Réessayer la même opération':'Enregistrer'}</button><button type="button" className="secondary" disabled={busy||uncertain} onClick={()=>{setMode('');setError('');}}>Annuler</button></div>
   </form>}
   {!mode&&!data.customer&&<p>Première visite : ajoutez ses informations ici. Déjà enregistré : utilisez la recherche.</p>}
  </>}
 </section>;
}
