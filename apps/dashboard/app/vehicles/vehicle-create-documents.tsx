'use client';

import {FileText, ShieldCheck, ClipboardCheck, Receipt, Route, Check} from 'lucide-react';
import './vehicle-create-documents.css';

export const creationDocumentKinds = [
 ['registration','Carte grise',FileText], ['insurance','Assurance',ShieldCheck],
 ['inspection','Visite technique',ClipboardCheck], ['tax','Vignette',Receipt],
 ['circulation','Feuille de circulation',Route],
] as const;
export type CreationDocumentKind = typeof creationDocumentKinds[number][0];
type Metadata = 'provider'|'policy_number'|'effective_on'|'expires_on'|'tax_year'|'paid_on';
export type CreationDocument = {
 key:string; file:File|null; values:Partial<Record<Metadata,string>>; attempted?:boolean; saved?:boolean;
};
export type CreationDocuments = Partial<Record<CreationDocumentKind,CreationDocument>>;
export const documentField = (kind:CreationDocumentKind,field:string)=>`document_${kind}_${field}`;

export function validateCreationDocuments(documents:CreationDocuments):Record<string,string[]> {
 const errors:Record<string,string[]>={};
 for(const [kind] of creationDocumentKinds){
  const d=documents[kind];if(!d||d.saved||d.attempted)continue;
  if(kind==='registration'&&!d.file)errors[documentField(kind,'file')]=['Joignez la carte grise ou choisissez « Compléter plus tard ».'];
  if(d.values.effective_on&&d.values.expires_on&&d.values.expires_on<=d.values.effective_on)
   errors[documentField(kind,'expires_on')]=['La fin de validité doit être après la date de début ou du contrôle.'];
 }
 return errors;
}

export function creationDocumentPayload(kind:CreationDocumentKind,d:CreationDocument){
 const data=new FormData();
 data.append('kind',kind);data.append('change_kind','initial');data.append('previous_id','');data.append('request_key',d.key);
 for(const [key,value] of Object.entries(d.values))if(value)data.append(key,value);
 if(d.file)data.append('file',d.file);
 return data;
}

export default function VehicleCreateDocuments({documents,onChange,errors,onErrors,today}:{
 documents:CreationDocuments;onChange:(next:CreationDocuments)=>void;errors:Record<string,string[]>;
 onErrors:(errors:Record<string,string[]>)=>void;today:string;
}){
 function update(kind:CreationDocumentKind,changes:Partial<CreationDocument>){
  const previous=documents[kind];if(!previous||previous.attempted||previous.saved)return;
  onChange({...documents,[kind]:{...previous,...changes}});
  onErrors(Object.fromEntries(Object.entries(errors).filter(([key])=>!key.startsWith(`document_${kind}_`))));
 }
 function field(kind:CreationDocumentKind,key:Metadata,label:string,required=false,type='text',extra:Record<string,string|number>={}){
  const name=documentField(kind,key),d=documents[kind]!;
  return <label className="v-field"><span>{label}{required?' *':' · facultatif'}</span><input name={name} aria-label={label} value={d.values[key]??''} required={required} type={type} {...extra} aria-invalid={!!errors[name]?.length} aria-describedby={errors[name]?.length?name+'-error':undefined} onChange={e=>update(kind,{values:{...d.values,[key]:e.target.value}})}/>{errors[name]?.[0]&&<small id={name+'-error'} className="v-error">{errors[name][0]}</small>}</label>;
 }
 return <div className="v-create-documents">
  <div className="v-section-heading"><span>05</span><div><h3>Documents du véhicule · Facultatif</h3><p>Ajoutez les pièces disponibles maintenant, ou complétez-les plus tard.</p></div></div>
  <p className="v-photo-note">Ces pièces seront liées au même fichier, dans « Documents & échéances ». Les documents manquants restent à compléter avant la remise du véhicule selon les contrôles requis.</p>
  {creationDocumentKinds.map(([kind,label,Icon])=>{
   const d=documents[kind],fileName=documentField(kind,'file');
   return <section className={'v-create-document '+(d?'selected':'')} key={kind} aria-label={label}>
    <div className="v-create-document-heading"><span className="v-create-document-icon"><Icon size={20}/></span><div><h4>{label}</h4><small>{d?.saved?'Enregistré dans Documents & échéances':d?.attempted?'Envoi à confirmer · réessayez l’enregistrement':d?'À enregistrer avec la voiture':'À compléter plus tard'}</small></div>{d?.saved?<Check size={20} aria-label="Enregistré"/>:d?<button type="button" className="text-button" disabled={d.attempted} onClick={()=>{if((d.file||Object.values(d.values).some(Boolean))&&!window.confirm('Retirer cette pièce non enregistrée ? Vous pourrez l’ajouter plus tard.'))return;const next={...documents};delete next[kind];onChange(next);onErrors(Object.fromEntries(Object.entries(errors).filter(([key])=>!key.startsWith(`document_${kind}_`))));}}>Compléter plus tard</button>:<button type="button" className="secondary" onClick={()=>onChange({...documents,[kind]:{key:crypto.randomUUID(),file:null,values:{}}})}>Ajouter</button>}</div>
    {d&&!d.saved&&<fieldset className="v-fieldset v-create-document-fields" disabled={d.attempted}>
     <div className="v-grid">
      {kind==='insurance'&&<>{field(kind,'provider','Compagnie d’assurance',true,'text',{maxLength:120})}{field(kind,'policy_number','N° de contrat',false,'text',{maxLength:100})}</>}
      {['insurance','inspection','circulation'].includes(kind)&&<>{field(kind,'effective_on',kind==='inspection'?'Date du contrôle réalisé':'Début de validité',true,'date',kind==='inspection'?{max:today}:{})}{field(kind,'expires_on',kind==='inspection'?'Prochain contrôle':'Fin de validité',true,'date')}</>}
      {kind==='tax'&&<>{field(kind,'tax_year','Année payée',true,'number',{min:1950,max:Number(today.slice(0,4))+1})}{field(kind,'paid_on','Date du paiement',true,'date',{max:today})}{field(kind,'expires_on','Date de rappel',false,'date')}</>}
     </div>
     <label className="v-field"><span>Justificatif {kind==='registration'?'*':'· facultatif'}</span><input type="file" name={fileName} accept="application/pdf,image/jpeg,image/png,image/webp" aria-invalid={!!errors[fileName]?.length} aria-describedby={fileName+'-help'+(errors[fileName]?.length?' '+fileName+'-error':'')} onChange={e=>{const file=e.target.files?.[0];e.target.value='';if(!file)return;if(file.size>5*1024*1024||!['application/pdf','image/jpeg','image/png','image/webp'].includes(file.type)){onErrors({...errors,[fileName]:['Choisissez un PDF, JPG, PNG ou WebP de 5 Mo maximum.']});return;}update(kind,{file});}}/><small id={fileName+'-help'}>PDF, JPG, PNG ou WebP · 5 Mo maximum · Document privé</small>{errors[fileName]?.[0]&&<small id={fileName+'-error'} className="v-error">{errors[fileName][0]}</small>}</label>
     {d.file&&<div className="v-create-document-file"><FileText size={16}/><span>{d.file.name}</span><button type="button" className="text-button" onClick={()=>update(kind,{file:null})}>Retirer le fichier</button></div>}
     {kind==='inspection'&&<p>Recopiez un contrôle déjà effectué. Si vous n’avez pas encore cette pièce, choisissez « Compléter plus tard ».</p>}
     {kind==='tax'&&<p>Le rappel est facultatif ; aucune échéance légale n’est calculée.</p>}
    </fieldset>}
   </section>;
  })}
 </div>;
}
