 'use client';
import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {Check,ArrowRight,X} from 'lucide-react';
import {reportFieldErrors,type FieldErrors} from '../_lib/form-validation';
import {apiMutation} from '../_lib/auth-client';
import {cents,money,date} from '../expenses/types';
import {entryNames,type CashData,type Entry} from './types';
export default function CashAction({mode,data,entry,onClose,onSaved}:{mode:'count'|'opening'|'movement';data:CashData;entry?:Entry;onClose:()=>void;onSaved:()=>void}){
 const dialog=useRef<HTMLDialogElement>(null),lock=useRef(false),pending=useRef<{path:string;body:Record<string,unknown>}|null>(null),key=useRef(crypto.randomUUID());
 const [kind,setKind]=useState(entry?.kind??(mode==='opening'?'opening':'bank_deposit')),[amount,setAmount]=useState(entry?String(entry.amount_centimes/100):''),[on,setOn]=useState(entry?.occurred_on??(mode==='count'?data.to:data.today)),[reason,setReason]=useState(''),[confirmed,setConfirmed]=useState(false),[busy,setBusy]=useState(false),[uncertain,setUncertain]=useState(false),[error,setError]=useState(''),[success,setSuccess]=useState(false),[outdated,setOutdated]=useState(false);
 useEffect(()=>{const origin=document.activeElement as HTMLElement;dialog.current?.showModal();return()=>origin?.focus()},[]);
 useEffect(()=>{const f=(e:BeforeUnloadEvent)=>{if(uncertain||busy||(!success&&(amount||reason)))e.preventDefault()};window.addEventListener('beforeunload',f);return()=>window.removeEventListener('beforeunload',f)},[uncertain,busy,success,amount,reason]);
 function close(){if(busy||uncertain)return;if(!success&&(amount||reason)&&!window.confirm('Quitter cette saisie sans l’enregistrer ?'))return;onClose()}
 let value:number|null=null;try{value=cents(amount)}catch{}
 const difference=value===null||data.cash.expected_centimes===null?null:value-data.cash.expected_centimes;
 async function save(){
  if(lock.current)return;
  if(!uncertain){const errors:FieldErrors={};
   if(value===null)errors.amount=['Saisissez un montant valide en DH.'];
   if(mode!=='count'&&!on)errors.on=['Renseignez la date.'];
   if(!confirmed)errors.confirmed=['Confirmez les montants avant de les enregistrer.'];
   if(mode!=='count'&&reason.trim().length<3)errors.reason=['Précisez le motif de cette opération.'];
   if(mode==='count'&&difference!==null&&difference!==0&&reason.trim().length<3)errors.reason=['Ajoutez une note pour expliquer le montant manquant ou en trop.'];
   if(reportFieldErrors(dialog.current,errors)){setError('Vérifiez les champs indiqués.');return;}

   pending.current=mode==='count'?{path:'cash/counts',body:{counted_on:on,counted_centimes:value,note:reason,expected_revision:data.cash.revision,confirmed:true,request_key:key.current}}:{path:'cash/entries'+(entry?'/'+entry.id:''),body:{kind,occurred_on:on,amount_centimes:value,reason,expected_version:entry?.version,confirmed:true,request_key:key.current}};
  }
  const operation=pending.current;if(!operation)return;lock.current=true;setBusy(true);setError('');let response:Response|undefined;
  try{response=await apiMutation(operation.path,'POST',operation.body);if(response.status===401){location.replace('/login');return}const body=await response.json();if(!response.ok){if(response.status<500){pending.current=null;setUncertain(false);key.current=crypto.randomUUID();setConfirmed(false);if(response.status===409){onSaved();if(entry)setOutdated(true)}}throw Error(response.status===409&&entry?'Cette opération a changé. Fermez cette fenêtre et rouvrez le mouvement pour consulter sa dernière version.':body.message??'Enregistrement impossible.')}pending.current=null;setUncertain(false);setSuccess(true);onSaved()}
  catch(e){if(pending.current&&(!response||response.ok||response.status>=500)){setUncertain(true);setError('Réponse incertaine. Réessayez la même opération pour vérifier son enregistrement, sans doublon.')}else setError(e instanceof Error?e.message:'Connexion impossible.')}
  finally{lock.current=false;setBusy(false)}
 }
 return createPortal(<dialog className="cs-dialog" ref={dialog} aria-labelledby="cs-dialog-title" onCancel={e=>{e.preventDefault();close()}}><header><div><span className="cs-eyebrow">{mode==='count'?'VÉRIFICATION DES ESPÈCES':entry?'CORRECTION CONSERVÉE DANS L’HISTORIQUE':'CAISSE DE L’AGENCE'}</span><h2 id="cs-dialog-title">{mode==='count'?'Compter la caisse':entry?'Corriger le montant':mode==='opening'?'Définir le solde de départ':'Déplacer des espèces'}</h2></div><button className="cs-close" aria-label="Fermer" disabled={busy||uncertain} onClick={close}><X size={20}/></button></header>
 <div className="cs-dialog-body">{error&&<p className="cs-alert" role="alert">{error}{uncertain&&<button disabled={busy} onClick={save}>Réessayer la même opération</button>}</p>}
 {success?<div className="cs-success" role="status"><Check size={28}/><h3>{mode==='count'?'Comptage enregistré':'Mouvement enregistré'}</h3><p>{mode==='count'?'Le montant compté et l’écart sont conservés. Aucun montant n’a été ajouté pour effacer une différence.':'La caisse est actualisée. Cette opération est conservée une seule fois.'}</p></div>:<>
 {mode==='count'?<div className="cs-count-expected"><span>Espèces attendues au {date(on)}</span><strong>{money(data.cash.expected_centimes??0)}</strong><small>Comptez les billets et pièces réellement présents, cautions en espèces comprises.</small></div>:mode==='opening'?<p className="cs-explanation">Indiquez l’argent présent <b>au début de la date choisie</b>, avant les encaissements et sorties de ce jour. Les mouvements plus anciens ne seront pas ajoutés à ce solde.</p>:<p className="cs-explanation">Pour un transfert banque ↔ caisse ou un apport / retrait du responsable. Une facture ou une vidange se règle dans son dossier.</p>}
 <fieldset disabled={busy||uncertain} onChange={()=>setConfirmed(false)}>{mode==='movement'&&!entry&&<div className="cs-kind-grid">{['bank_deposit','bank_withdrawal','cash_in','cash_out'].map(k=><button type="button" key={k} aria-pressed={kind===k} onClick={()=>{setKind(k);setConfirmed(false)}}>{entryNames[k]}</button>)}</div>}
 {entry&&<p>{entryNames[entry.kind]} · {date(entry.occurred_on)} · ancien montant : <b>{money(entry.amount_centimes)}</b></p>}
 <div className="cs-fields"><label htmlFor="cs-amount">{mode==='count'?'Espèces réellement comptées · DH':entry?'Montant correct · DH':'Montant · DH'}<input data-validation-field="amount" id="cs-amount" inputMode="decimal" autoComplete="off" placeholder="0,00" value={amount} onChange={e=>setAmount(e.target.value)}/></label>{mode!=='count'&&<label>Date{mode==='opening'?' de début du suivi':''}<input data-validation-field="on" type="date" max={data.today} min={mode==='movement'?data.cash.opening?.date:undefined} disabled={!!entry} value={on} onChange={e=>setOn(e.target.value)}/></label>}</div>
 {entry&&entry.kind!=='opening'&&<p className="cs-hint">Pour annuler une saisie faite par erreur, indiquez 0 et expliquez le motif. L’original reste conservé.</p>}
 {mode==='count'&&difference!==null&&<div className={'cs-difference '+(difference===0?'balanced':'discrepancy')}><span>{difference===0?'La caisse correspond':difference<0?'Il manque':'Il y a en trop'}</span><strong>{money(Math.abs(difference))}</strong></div>}
 <label className="cs-note">{mode==='count'?'Note · obligatoire en cas d’écart':'Motif de l’opération'}<textarea data-validation-field="reason" maxLength={500} value={reason} placeholder={mode==='count'?'Une explication ou une observation…':'Ex. dépôt des espèces à la banque'} onChange={e=>setReason(e.target.value)}/></label>
 </fieldset><label className="cs-check"><input data-validation-field="confirmed" type="checkbox" disabled={busy||uncertain} checked={confirmed} onChange={e=>setConfirmed(e.target.checked)}/><span>{mode==='count'?'J’ai compté les espèces et vérifié ce montant.':'Je confirme ce montant et cette opération réelle, sans doublon.'}</span></label>
 </> }</div><footer><span>Montants en DH · historique conservé</span>{success?<button className="primary" onClick={onClose}>Terminé <Check size={16}/></button>:<button className="primary" disabled={busy||uncertain||!data.ready||outdated} onClick={save}>{busy?'Enregistrement…':mode==='count'?'Enregistrer le comptage':'Enregistrer'} <ArrowRight size={16}/></button>}</footer></dialog>,document.body)
}
