'use client';
import {useEffect,useRef,useState,type FormEvent} from 'react';
import {Building2,FileText,ImagePlus,Save,ShieldCheck,RefreshCw} from 'lucide-react';
import {api,apiMutation} from '../../_lib/auth-client';
import {validateFields,focusFieldError} from '../../_lib/form-validation';
type Profile={details:Record<string,string>;revision:string;logo:string|null;history:{id:number;created_at:string;actor:string}[]};
export default function AgencyProfile(){
 const [data,setData]=useState<Profile|null>(null),[values,setValues]=useState<Record<string,string>>({});
 const [file,setFile]=useState<File|null>(null),[remove,setRemove]=useState(false),[preview,setPreview]=useState('');
 const [busy,setBusy]=useState(false),[loading,setLoading]=useState(true),[error,setError]=useState(''),[notice,setNotice]=useState('');
 const [errors,setErrors]=useState<Record<string,string[]>>({}),[conflict,setConflict]=useState(false);
 const form=useRef<HTMLFormElement>(null);
 const dirty=!!data&&(JSON.stringify(values)!==JSON.stringify(data.details)||!!file||remove);
 function apply(d:Profile){setData(d);setValues(d.details);setFile(null);setRemove(false);setErrors({});setConflict(false);if(form.current){const input=form.current.querySelector<HTMLInputElement>('input[type=file]');if(input)input.value='';}}
 async function load(){if(dirty&&!window.confirm('Abandonner vos modifications non enregistrées ?'))return;setLoading(true);setError('');try{const r=await fetch(api+'/api/v1/settings/agency',{credentials:'include',headers:{Accept:'application/json'},cache:'no-store'});const b=await r.json();if(!r.ok)throw Error(b.message??'Chargement impossible.');apply(b);}catch(e){setError(e instanceof Error?e.message:'Chargement impossible.');}finally{setLoading(false);}}
 useEffect(()=>{void load();},[]);
 useEffect(()=>{const stop=(e:BeforeUnloadEvent)=>{if(dirty){e.preventDefault();e.returnValue='';}};window.addEventListener('beforeunload',stop);return()=>window.removeEventListener('beforeunload',stop);},[dirty]);
 useEffect(()=>{if(!file){setPreview('');return;}const u=URL.createObjectURL(file);setPreview(u);return()=>URL.revokeObjectURL(u);},[file]);
 function field(name:string,label:string,required=false,type='text',max=255){
  return <label className="ap-field" htmlFor={'ap-'+name}>{label}{required&&' *'}<input id={'ap-'+name} name={name} value={values[name]??''} required={required} type={type} maxLength={max}
    onChange={e=>{setValues(v=>({...v,[name]:e.target.value}));setErrors(v=>({...v,[name]:[]}));setNotice('');}}
    aria-invalid={!!errors[name]?.length} aria-describedby={errors[name]?.length?'ap-error-'+name:undefined}/>
    {errors[name]?.[0]&&<small id={'ap-error-'+name} className="ap-field-error">{errors[name][0]}</small>}</label>;
 }
 async function save(e:FormEvent<HTMLFormElement>){
  e.preventDefault();if(busy||!data)return;
  const invalid=validateFields(e.currentTarget);if(file&&file.size>1048576)invalid.logo=['Choisissez une image de 1 Mo maximum.'];
  setErrors(invalid);if(Object.keys(invalid).length){focusFieldError(e.currentTarget,invalid);return;}
  setBusy(true);setError('');setNotice('');
  try{
   const body=new FormData();['name','address','phone','email','ice','rc','if','tp','capital'].forEach(k=>body.append(k,values[k]??''));
   body.append('expected_revision',data.revision);body.append('remove_logo',remove?'1':'0');if(file)body.append('logo',file);
   const r=await apiMutation('settings/agency','POST',body);const b=await r.json();
   if(!r.ok){if(b.errors){setErrors(b.errors);setTimeout(()=>{if(form.current)focusFieldError(form.current,b.errors);},0);}setConflict(r.status===409);throw Error(b.message??'Enregistrement impossible.');}
   apply(b);setNotice('Informations enregistrées. Vos documents déjà émis restent inchangés.');window.dispatchEvent(new Event('agency-profile-updated'));
  }catch(e){setError(e instanceof Error?e.message:'Réponse incertaine. Rechargez le profil pour vérifier l’enregistrement.');}finally{setBusy(false);}
 }
 return <div className="agency-profile">
  <header className="ap-heading"><div><p className="ap-eyebrow">PARAMÈTRES / MON AGENCE</p><h1>Mon agence</h1><p>Les coordonnées et l’identité de votre entreprise, au même endroit.</p></div><a href="/settings/backups">Sauvegardes ↗</a></header>
  <nav className="ap-nav" aria-label="Paramètres"><a href="/settings/agency" aria-current="page"><Building2 size={17}/>Mon agence</a><a href="/settings/backups">Sauvegardes</a></nav>
  {loading?<p role="status">Chargement des informations…</p>:!data?<div role="alert"><p>{error}</p><button onClick={()=>void load()}>Réessayer</button></div>:
  <form ref={form} onSubmit={save} noValidate aria-busy={busy}>
   <div className="ap-notice"><ShieldCheck size={20}/><p>Les modifications s’appliquent aux nouveaux documents. Les contrats et factures déjà émis conservent leurs informations d’origine.</p></div>
   <fieldset disabled={busy}>
   <div className="ap-columns"><div className="ap-main">
    <section className="ap-panel"><h2><Building2 size={20}/>Coordonnées de l’agence</h2><p>Utilisées pour vous identifier et vous contacter.</p><div className="ap-grid">
     <div className="ap-wide">{field('name','Nom de l’agence',true)}</div>
     {field('phone','Téléphone',true,'tel',40)}{field('email','E-mail',false,'email')}
     <div className="ap-wide">{field('address','Adresse complète',true,'text',500)}</div>
    </div></section>
    <section className="ap-panel"><h2><FileText size={20}/>Informations légales</h2><p>Recopiez les références figurant sur les documents de votre entreprise.</p><div className="ap-grid">
     {field('ice','ICE · 15 chiffres',false,'text',15)}{field('rc','Registre de commerce (RC)',false,'text',100)}
     {field('if','Identifiant fiscal (IF)',false,'text',100)}{field('tp','Taxe professionnelle',false,'text',100)}
     {field('capital','Capital social · montant et devise',false,'text',100)}
    </div></section>
   </div><aside className="ap-side">
    <section className="ap-panel"><h2><ImagePlus size={20}/>Logo de l’agence</h2><div className="ap-logo">{preview||(!remove&&data.logo)?<img src={preview||data.logo!} alt="Logo de l’agence"/>:<div><Building2 size={32}/><p>{values.name||'Votre agence'}</p></div>}</div>
     <label className="ap-upload">Choisir un logo<input name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-invalid={!!errors.logo?.length} onChange={e=>{const f=e.target.files?.[0]??null;setFile(f);setRemove(false);setNotice('');setErrors(v=>({...v,logo:f&&f.size>1048576?['Choisissez une image de 1 Mo maximum.']:[]}));}}/></label>
     <small>PNG, JPG ou WebP · 1 Mo maximum.</small>{errors.logo?.[0]&&<p className="ap-field-error">{errors.logo[0]}</p>}
     {(data.logo||file)&&<button className="ap-remove" type="button" onClick={()=>{setFile(null);setRemove(true);const i=form.current?.querySelector<HTMLInputElement>('input[type=file]');if(i)i.value='';}}>Retirer le logo</button>}
     {remove&&<p>Le nom de l’agence sera affiché à la place du logo.</p>}
    </section>
    <section className="ap-panel ap-history"><h2>Dernières modifications</h2>{data.history.length?data.history.map(h=><div key={h.id}><strong>{h.actor}</strong><time dateTime={h.created_at}>{new Intl.DateTimeFormat('fr-MA',{dateStyle:'medium',timeStyle:'short',timeZone:'Africa/Casablanca'}).format(new Date(h.created_at))}</time></div>):<p>Aucune modification depuis la configuration.</p>}</section>
   </aside></div></fieldset>
   {error&&<p className="ap-error" role="alert">{error}</p>}
   {conflict&&<button type="button" onClick={()=>void load()}><RefreshCw size={16}/>Recharger les informations</button>}
   {notice&&<p className="ap-success" role="status">{notice}</p>}
   <div className="ap-savebar"><span>{dirty?'Modifications non enregistrées':'Informations à jour'}</span><div><button type="button" disabled={busy||!dirty} onClick={()=>{apply(data);setNotice('');setError('');}}>Annuler</button><button className="ap-save" type="submit" disabled={busy||!dirty||conflict}><Save size={17}/>{busy?'Enregistrement…':'Enregistrer les modifications'}</button></div></div>
  </form>}
 </div>;
}
