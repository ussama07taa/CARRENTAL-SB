'use client';
import {useEffect,useRef,useState,type FormEvent} from 'react';
import {Building2,Check,ShieldCheck,ArrowRight,ArrowLeft} from 'lucide-react';
import {api,apiMutation} from '../_lib/auth-client';
import {validateFields,focusFieldError} from '../_lib/form-validation';

const account=['installation_code','responsible_name','email','password','password_confirmation'];
export default function Setup(){
 const [available,setAvailable]=useState<boolean|null>(null),[loadingError,setLoadingError]=useState(false);
 const [step,setStep]=useState(0),[busy,setBusy]=useState(false),[done,setDone]=useState(false),[error,setError]=useState('');
 const [errors,setErrors]=useState<Record<string,string[]>>({}),[values,setValues]=useState<Record<string,string>>({});
 const [logo,setLogo]=useState<File|null>(null),[preview,setPreview]=useState('');
 const form=useRef<HTMLFormElement>(null),heading=useRef<HTMLHeadingElement>(null);
 async function load(){setLoadingError(false);try{const r=await fetch(api+'/api/v1/installation',{cache:'no-store'});if(!r.ok)throw Error();setAvailable((await r.json()).available);}catch{setLoadingError(true);}}
 useEffect(()=>{void load();},[]);
 useEffect(()=>{heading.current?.focus();},[step,done]);
 useEffect(()=>{if(!logo){setPreview('');return;}const url=URL.createObjectURL(logo);setPreview(url);return()=>URL.revokeObjectURL(url);},[logo]);
 function field(name:string,label:string,type='text',required=false,autoComplete?:string){
  return <label className="setup-field" key={name} htmlFor={'setup-'+name}>{label}{required&&<span aria-hidden="true"> *</span>}
   <input id={'setup-'+name} name={name} type={type} required={required} autoComplete={autoComplete}
    maxLength={type==='password'?72:name==='address'?500:255} minLength={type==='password'?12:undefined}
    value={values[name]??''} onChange={e=>{setValues(v=>({...v,[name]:e.target.value}));setErrors(v=>({...v,[name]:[]}));}}
    aria-invalid={!!errors[name]?.length} aria-describedby={errors[name]?.length?'error-'+name:undefined}/>
   {errors[name]?.[0]&&<small id={'error-'+name} className="setup-field-error">{errors[name][0]}</small>}
  </label>;
 }
 async function submit(e:FormEvent<HTMLFormElement>){
  e.preventDefault();if(busy)return;
  const invalid=validateFields(e.currentTarget);
  if(step===0&&values.password!==values.password_confirmation)invalid.password_confirmation=['Les mots de passe ne correspondent pas.'];
  if(Object.keys(invalid).length){setErrors(invalid);const target=e.currentTarget;setTimeout(()=>focusFieldError(target,invalid),0);return;}
  if(step===0){setStep(1);setError('');return;}
  setBusy(true);setError('');
  try{
   const body=new FormData();Object.entries(values).forEach(([k,v])=>body.append(k,v));if(logo)body.append('logo',logo);
   const res=await apiMutation('installation','POST',body);const b=await res.json();
   if(!res.ok){
    if(b.errors){setErrors(b.errors);if(Object.keys(b.errors).some(k=>account.includes(k)))setStep(0);setTimeout(()=>{if(form.current)focusFieldError(form.current,b.errors);},50);}
    if(res.status===409)setAvailable(false);
    throw Error(b.message??'Vérifiez les informations et réessayez.');
   }
   setValues({});setLogo(null);setDone(true);
  }catch(err){setError(err instanceof Error?err.message:'Connexion interrompue. Vérifiez si votre compte a été créé en vous connectant avant de réessayer.');}
  finally{setBusy(false);}
 }
 return <main className="setup-shell">
  <div className="setup-brand"><Building2 aria-hidden="true"/><span>Votre espace agence</span></div>
  <section className="setup-card">
   <aside className="setup-aside"><span className="setup-tag">PREMIÈRE CONNEXION</span><h1>Votre agence.<br/> Un nouveau départ.</h1><p>Créez votre accès et personnalisez votre espace de gestion.</p>
    <ol>{['Votre compte gérant','Votre agence'].map((s,i)=><li key={s} aria-current={!done&&i===step?'step':undefined}><span>{done||i<step?<Check size={18}/>:i+1}</span>{s}</li>)}</ol>
    <div className="setup-assurance"><ShieldCheck size={20}/><p>Un espace privé pour votre équipe. Vous ajouterez ensuite vos voitures et vos clients.</p></div>
   </aside>
   <div className="setup-content">
   {done?<><h2 ref={heading} tabIndex={-1}>Votre agence est prête.</h2><p>Votre compte gérant a été créé. Connectez-vous pour ajouter votre première voiture.</p><a className="setup-primary" href="/login">Se connecter <ArrowRight size={18}/></a></>:
    available===false?<><h2>Installation déjà configurée ou non activée</h2><p>Si votre compte existe, connectez-vous. Pour un nouveau démarrage, contactez votre installateur.</p><a className="setup-primary" href="/login">Aller à la connexion</a></>:
    available===null?<><h2>Préparation de votre espace</h2><p role="status">{loadingError?'Le service est indisponible.':'Vérification de l’installation…'}</p>{loadingError&&<button onClick={()=>void load()}>Réessayer</button>}</>:
    <form ref={form} noValidate onSubmit={submit} aria-busy={busy}>
     <p className="setup-step">ÉTAPE {step+1} SUR 2</p><h2 ref={heading} tabIndex={-1}>{step===0?'Créons votre compte gérant':'Présentez votre agence'}</h2>
     <p className="setup-intro">{step===0?'Vos identifiants personnels pour gérer votre activité.':'Ces coordonnées identifieront votre agence dans vos documents.'} Les champs * sont obligatoires.</p>
     <fieldset disabled={busy}>
     {step===0?<div className="setup-grid">
      <div className="setup-wide">{field('installation_code','Code d’installation','password',true,'off')}<small>Le code privé remis par votre installateur.</small></div>
      <div className="setup-wide">{field('responsible_name','Nom complet','text',true,'name')}</div>
      <div className="setup-wide">{field('email','E-mail de connexion','email',true,'username')}</div>
      {field('password','Mot de passe','password',true,'new-password')}{field('password_confirmation','Confirmer le mot de passe','password',true,'new-password')}
      <small className="setup-wide">12 caractères minimum. Conservez vos identifiants dans un endroit sûr.</small>
     </div>:<div className="setup-grid">
      <div className="setup-wide">{field('company_name','Nom de l’agence','text',true,'organization')}</div>
      {field('phone','Téléphone','tel',true,'tel')}{field('agency_email','E-mail de l’agence','email',false,'email')}
      <div className="setup-wide">{field('address','Adresse complète','text',true,'street-address')}</div>
      <label className="setup-upload setup-wide">Logo de l’agence <span>Facultatif · PNG, JPG ou WebP · 1 Mo maximum</span>
       <input name="logo" type="file" accept="image/png,image/jpeg,image/webp" onChange={e=>{const f=e.target.files?.[0]??null;if(f&&f.size>1024*1024){setErrors(v=>({...v,logo:['Choisissez un logo de 1 Mo maximum.']}));e.target.value='';setLogo(null);}else{setLogo(f);setErrors(v=>({...v,logo:[]}));}}}/>
       {preview&&<img src={preview} alt="Aperçu du logo" width={150} height={60}/>}
       {errors.logo?.[0]&&<small className="setup-field-error">{errors.logo[0]}</small>}
      </label>
      <details className="setup-wide" open={['ice','rc','if','tp','capital'].some(k=>errors[k]?.length)||undefined}><summary>Informations légales <span>Facultatives à cette étape</span></summary><p>À renseigner et vérifier avant l’émission de vos documents définitifs.</p><div className="setup-grid">
       {field('ice','ICE (15 chiffres)')}{field('rc','Registre de commerce')}{field('if','Identifiant fiscal')}{field('tp','Taxe professionnelle')}{field('capital','Capital social')}
      </div></details>
     </div>}
     </fieldset>
     {error&&<p className="setup-error" role="alert">{error}</p>}
     <div className="setup-actions">{step>0&&<button type="button" disabled={busy} onClick={()=>setStep(0)}><ArrowLeft size={17}/>Retour</button>}
      <button className="setup-primary" type="submit" disabled={busy}>{busy?'Création en cours…':step===0?'Continuer':'Créer mon agence'}<ArrowRight size={18}/></button>
     </div>
    </form>}
   </div>
  </section>
  <footer>Configuration initiale · Accès sécurisé à votre agence</footer>
 </main>;
}
