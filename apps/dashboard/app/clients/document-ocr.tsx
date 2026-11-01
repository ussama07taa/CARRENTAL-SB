"use client";
import {useEffect,useRef,useState} from 'react';
import {ScanLine} from 'lucide-react';
import {api,apiMutation} from '../_lib/auth-client';
import './document-ocr.css';
type Values={first_name:string;last_name:string;identity_type:string;identity_number:string;licence_number:string;licence_expires_on:string};
const names:Record<string,string>={first_name:'Prénom',last_name:'Nom',cin_number:'Numéro CIN (type : CIN)',licence_number:'Numéro du permis',expiry_date:'Validité du permis',birth_date:'Date de naissance',issue_date:'Date de délivrance'};
const targets:Record<string,keyof Values>={first_name:'first_name',last_name:'last_name',cin_number:'identity_number',licence_number:'licence_number',expiry_date:'licence_expires_on'};
export default function DocumentOcr({values,customerId,disabled,onApply,source,onBusyChange}:{values:Values;customerId?:number;disabled:boolean;source?:{file:File;kind:'cin'|'permis'};onBusyChange?:(busy:boolean)=>void;onApply:(patch:Partial<Values>)=>void}){
 const [kind,setKind]=useState<string>(source?.kind??'cin'),[file,setFile]=useState<File|null>(source?.file??null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState(''),[result,setResult]=useState<Record<string,string>|null>(null),[selected,setSelected]=useState<Record<string,boolean>>({}),[reviewed,setReviewed]=useState(false);
 const [allowed,setAllowed]=useState(false),[accessMessage,setAccessMessage]=useState('Vérification de l’accès à la lecture…');
 useEffect(()=>{const abort=new AbortController();fetch(api+'/api/v1/customers/ocr',{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:abort.signal}).then(r=>r.ok?r.json():null).then(data=>{if(!abort.signal.aborted){setAllowed(data?.allowed===true);setAccessMessage(data?.allowed?'':data?.message||'Lecture indisponible. Vérifiez votre connexion et rouvrez le fichier client.');}}).catch(()=>{if(!abort.signal.aborted)setAccessMessage('Impossible de vérifier l’accès. Rouvrez le fichier client pour réessayer.');});return()=>abort.abort();},[]);
 useEffect(()=>{onBusyChange?.(busy);return()=>onBusyChange?.(false);},[busy,onBusyChange]);
 const latestValues=useRef(values);latestValues.current=values;
 const applyLatest=useRef(onApply);applyLatest.current=onApply;
 const autoStarted=useRef(false);
 const alive=useRef(true);useEffect(()=>{alive.current=true;return()=>{alive.current=false;};},[]);
 function clear(){setResult(null);setSelected({});setReviewed(false);setError('');setNotice('');}
 async function read(){if(!file||busy)return;clear();setBusy(true);try{
  const data=new FormData();data.set('image',file);data.set('kind',kind);if(customerId)data.set('customer_id',String(customerId));
  const response=await apiMutation('customers/ocr','POST',data,170000);const body=await response.json();
  if(!response.ok)throw Error(body.errors?Object.values(body.errors).flat().join(' '):body.message||'Lecture impossible.');
  if(alive.current){
   setResult(body.fields);
   const patch:Partial<Values>={};
   for(const [field,value] of Object.entries(body.fields as Record<string,string>)){
    const target=targets[field];if(!target||!value.trim()||latestValues.current[target]?.trim()||(field==='expiry_date'&&kind!=='permis'))continue;
    if(field==='cin_number'&&latestValues.current.identity_type&&latestValues.current.identity_type!=='cin')continue;
    patch[target]=value.trim();if(field==='cin_number')patch.identity_type='cin';
   }
   if(Object.keys(patch).length){applyLatest.current(patch);setNotice('Les champs vides ont été remplis automatiquement. Vérifiez-les avec le document avant d’enregistrer le client.');}
   else setNotice('Lecture terminée. Les informations déjà saisies ont été conservées. Consultez les propositions ci-dessous.');
  }
 }catch(e){if(alive.current)setError(e instanceof Error?e.message:'Lecture indisponible.');}finally{if(alive.current)setBusy(false);}}
 useEffect(()=>{if(allowed&&source&&!autoStarted.current){autoStarted.current=true;void read();}},[allowed]);
 function apply(){if(!result||!reviewed)return;const patch:Partial<Values>={};for(const [field,value]of Object.entries(result)){
  const target=targets[field];if(!selected[field]||!target||!value.trim()||(field==='expiry_date'&&kind!=='permis'))continue;
  patch[target]=value.trim();if(field==='cin_number')patch.identity_type='cin';
 }onApply(patch);clear();setNotice('Informations appliquées au formulaire. Enregistrez le client pour les conserver.');}
 if(!allowed)return <div className="customer-ocr" role="status"><strong>Lecture automatique</strong><p>{accessMessage}</p></div>;
 return <section className="customer-ocr" aria-label="Lecture automatique de documents"><h3><ScanLine size={20} aria-hidden="true"/> Lecture automatique du document</h3><p>Les champs vides se remplissent après l’ajout de la photo. Vérifiez les informations avant d’enregistrer.</p>
 {source?<p>Photo sélectionnée : {source.file.name}</p>:<div className="ocr-controls"><label>Document<select data-validation-field="kind" value={kind} disabled={busy||disabled} onChange={e=>{setKind(e.target.value);clear();}}><option value="cin">CIN marocaine</option><option value="permis">Permis marocain</option></select></label><label>Photo à lire<input type="file" accept="image/jpeg,image/png,image/webp" disabled={busy||disabled} onChange={e=>{clear();const f=e.target.files?.[0]??null;if(f&&f.size>5*1024*1024){setFile(null);setError('5 Mo maximum par image.');return;}setFile(f);}}/></label></div>}
 <small>{source?'La lecture utilise cette photo. Son enregistrement reste lié à la sauvegarde du client.':'Une face à la fois · Cette lecture ne joint pas la photo au dossier.'}</small>
 <button type="button" className="secondary" disabled={!file||busy||disabled} onClick={read}>{busy?'Lecture en cours…':source?'Relire la photo':'Lire le document'}</button>
 <div role="status" aria-live="polite">{busy&&<p>Analyse locale en cours, jusqu’à quelques minutes. La saisie manuelle reste disponible.</p>}{notice&&<p>{notice}</p>}</div>{error&&<p role="alert" className="field-error">{error}</p>}
 {result&&<div className="ocr-review"><h4>Comparer et choisir les informations</h4><p>Les champs déjà renseignés restent inchangés. Sélectionnez une proposition uniquement pour les remplacer.</p>{Object.keys(result).length===0&&<p>Aucune proposition fiable. Essayez une photo plus proche, à plat et sans reflet.</p>}
 {Object.entries(result).map(([field,value])=>{const target=targets[field];const writable=!!target&&(field!=='expiry_date'||kind==='permis');return <div className="ocr-proposal" key={field}>
 {writable?<label className="ocr-choice"><input type="checkbox" checked={!!selected[field]} disabled={disabled} onChange={e=>{setSelected({...selected,[field]:e.target.checked});setReviewed(false);}}/>{names[field]||field}</label>:<strong>{names[field]||field} · lecture uniquement</strong>}
 {writable&&<small>Actuel : {values[target]||'Non renseigné'}{field==='cin_number'&&values.identity_type?' · '+values.identity_type.toUpperCase():''}</small>}
 <label>Proposition<input data-validation-field="value" value={value} type={field.endsWith('_date')?'date':'text'} disabled={disabled||!writable} onChange={e=>{setResult({...result,[field]:e.target.value});setReviewed(false);}}/></label>
 {!writable&&<small>Ce champ ne sera pas enregistré dans le fichier client actuel.</small>}</div>;})}
 <label className="ocr-choice"><input data-validation-field="reviewed" type="checkbox" checked={reviewed} disabled={disabled} onChange={e=>setReviewed(e.target.checked)}/>J’ai comparé les informations sélectionnées avec le document.</label>
 <div className="ocr-buttons"><button type="button" className="primary" disabled={disabled||!reviewed||!Object.values(selected).some(Boolean)} onClick={apply}>Appliquer les informations</button><button type="button" className="secondary" onClick={clear}>Ignorer les propositions</button></div></div>}
 </section>;
}
