'use client';
import {useEffect,useRef,useState} from 'react';
import {apiMutation} from '../_lib/auth-client';
import type {Client} from './request-types';
import './new-request-client.css';

export type NewClientState={dirty:boolean;busy:boolean;uncertain:boolean};
export default function NewRequestClient({onSelect,onCancel,onStateChange}:{onSelect:(client:Client,created:boolean)=>void;onCancel:()=>void;onStateChange:(state:NewClientState)=>void}){
 const [values,setValues]=useState({first_name:'',last_name:'',phone:'',language:'fr'}),[fields,setFields]=useState<Record<string,string[]>>({}),[error,setError]=useState(''),[busy,setBusy]=useState(false),[uncertain,setUncertain]=useState(false),[duplicates,setDuplicates]=useState<Client[]>([]);
 const root=useRef<HTMLFieldSetElement>(null),lock=useRef(false),key=useRef(crypto.randomUUID()),pending=useRef<Record<string,unknown>|null>(null);
 const dirty=!!(values.first_name||values.last_name||values.phone||values.language!=='fr');
 useEffect(()=>{root.current?.querySelector<HTMLInputElement>('input')?.focus();},[]);
 useEffect(()=>{onStateChange({dirty,busy,uncertain});},[dirty,busy,uncertain,onStateChange]);
 useEffect(()=>()=>onStateChange({dirty:false,busy:false,uncertain:false}),[onStateChange]);
 useEffect(()=>{const name=['first_name','last_name','phone','language'].find(name=>fields[name]?.length);if(name)root.current?.querySelector<HTMLInputElement>(`[name="${name}"]`)?.focus();},[fields]);
 function change(name:keyof typeof values,value:string){setValues(old=>({...old,[name]:value}));setFields(old=>{const next={...old};delete next[name];return next;});setError('');setDuplicates([]);pending.current=null;}
 async function save(retry=false,acknowledge=false){
  if(lock.current)return;
  if(!retry){const errors:Record<string,string[]>={};for(const field of ['first_name','last_name','phone'] as const)if(!values[field].trim())errors[field]=['Ce champ est obligatoire.'];setFields(errors);if(Object.keys(errors).length)return;pending.current={...values,creation_key:key.current,acknowledge_duplicates:acknowledge};}
  lock.current=true;setBusy(true);onStateChange({dirty:true,busy:true,uncertain});setError('');
  try{const response=await apiMutation('customers','POST',pending.current);if(response.status>=500)throw new TypeError('Réponse incertaine.');const data=await response.json();
   if(response.status===401){setError('Votre session a expiré. Reconnectez-vous avant de continuer.');setUncertain(true);return;}
   if(!response.ok){pending.current=null;setUncertain(false);setFields(data.errors??{});setDuplicates(data.code==='POSSIBLE_DUPLICATE'?data.duplicates??[]:[]);setError(data.message??'Le client n’a pas pu être enregistré.');return;}
   pending.current=null;setUncertain(false);onSelect(data.data,true);
  }catch(e){const unknown=e instanceof TypeError||(e instanceof DOMException&&['TimeoutError','AbortError'].includes(e.name));setUncertain(unknown);setError(unknown?'La réponse est incertaine. Vérifiez le même enregistrement pour éviter un doublon.':(e as Error).message);}
  finally{lock.current=false;setBusy(false);}
 }
 return <div className="rq-new-client" role="region" aria-label="Nouveau client dans la demande">
  <h4>Nouveau client</h4><p>Créez son fichier permanent sans quitter la demande. La voiture et les dates restent conservées. Les pièces et photos pourront être complétées avant le départ.</p>
  <fieldset ref={root} disabled={busy||uncertain} onKeyDown={e=>{if(e.key==='Enter'&&e.target instanceof HTMLInputElement){e.preventDefault();void save();}}}><legend className="sr-only">Informations du nouveau client</legend><div className="rq-new-client-grid">
   {([{name:'first_name',label:'Prénom',auto:'given-name',max:100},{name:'last_name',label:'Nom',auto:'family-name',max:100},{name:'phone',label:'Téléphone',auto:'tel',max:30}] as const).map(field=><div key={field.name}><label htmlFor={'rq-new-'+field.name}>{field.label} *</label><input id={'rq-new-'+field.name} name={field.name} type={field.name==='phone'?'tel':'text'} autoComplete={field.auto} value={values[field.name]} maxLength={field.max} required aria-invalid={!!fields[field.name]} aria-describedby={fields[field.name]?'rq-new-error-'+field.name:field.name==='phone'?'rq-new-phone-help':undefined} onChange={e=>change(field.name,e.target.value)}/>{field.name==='phone'&&<small id="rq-new-phone-help">Ex. 06 12 34 56 78 ou +212 612345678.</small>}{fields[field.name]&&<p id={'rq-new-error-'+field.name} className="rq-field-error" role="alert">{fields[field.name][0]}</p>}</div>)}
   <div><label htmlFor="rq-new-language">Langue des documents du client</label><select id="rq-new-language" name="language" value={values.language} onChange={e=>change('language',e.target.value)}><option value="fr">Français</option><option value="ar">Arabe</option></select>{fields.language&&<p className="rq-field-error" role="alert">{fields.language[0]}</p>}</div>
  </div></fieldset>
  {error&&<div className="rq-new-client-error" role="alert">{error}</div>}
  {duplicates.length>0&&<div className="rq-new-duplicates"><strong>Ces clients existent déjà. Vérifiez avant de créer un doublon.</strong>{duplicates.map(client=><button type="button" className="secondary" key={client.id} disabled={busy||uncertain} onClick={()=>onSelect(client,false)}><span>{client.first_name} {client.last_name} · {client.phone}</span>Utiliser ce client</button>)}<button type="button" className="rq-link" disabled={busy||uncertain} onClick={()=>void save(false,true)}>Il s’agit d’une autre personne : créer un fichier distinct</button></div>}
  <div className="rq-new-client-actions">{uncertain?<button type="button" className="primary" disabled={busy} onClick={()=>void save(true)}>Vérifier le même enregistrement</button>:<button type="button" className="primary" disabled={busy} onClick={()=>void save()}>{busy?'Enregistrement du client…':'Enregistrer et choisir ce client'}</button>}<button type="button" className="secondary" disabled={busy||uncertain} onClick={onCancel}>Revenir aux clients existants</button></div>
 </div>;
}
