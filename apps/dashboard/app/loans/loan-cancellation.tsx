'use client';
import {useRef,useState} from 'react';
import {apiMutation} from '../_lib/auth-client';
import {Field,Check,focusError,responseErrors,type Errors} from './loan-controls';
import {money,type LoanFile} from './loan-types';
export default function LoanCancellation({file,onClose,onSaved,onStale,onBusy}:{file:LoanFile;onClose:()=>void;onSaved:()=>void;onStale:(message:string)=>void;onBusy:(busy:boolean)=>void}){
 const root=useRef<HTMLElement>(null),lock=useRef(false),key=useRef(crypto.randomUUID());
 const [reason,setReason]=useState(''),[confirmed,setConfirmed]=useState(false),[errors,setErrors]=useState<Errors>({}),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const blocked=file.data.opening_paid_centimes>0||file.data.recorded_paid_centimes>0;
 async function save(){
  if(lock.current||blocked)return;const fields:Errors={};
  if(!reason.trim())fields.reason='Expliquez pourquoi ce contrat a été enregistré par erreur.';
  if(!confirmed)fields.confirmed='Confirmez qu’il s’agit d’une erreur d’enregistrement.';
  setErrors(fields);if(Object.keys(fields).length){focusError(root.current,fields);return;}
  lock.current=true;setBusy(true);onBusy(true);setError('');
  try{
   const response=await apiMutation(`loans/${file.data.id}/cancellations`,'POST',{reason:reason.trim(),confirmed:true,financial_revision:file.financial_revision,expected_loan_remaining_centimes:file.data.remaining_centimes,request_key:key.current});
   const body=await response.json();
   if(response.status===401){window.location.replace('/login');return;}
   if(response.status===409){onStale(body.message??'Le dossier a changé. Actualisez les montants.');return;}
   if(!response.ok){const fields=responseErrors(body);setErrors(fields);focusError(root.current,fields);if(response.status===422)key.current=crypto.randomUUID();throw Error(fields.loan??body.message??'Annulation non enregistrée.');}
   onSaved();
  }catch(e){setError((e as Error).message);}finally{lock.current=false;setBusy(false);onBusy(false);}
 }
 return <section className="lc-correction lc-cancellation" id="loan-contract-cancellation" ref={root} aria-labelledby="loan-contract-cancellation-title">
  <div className="lc-section-title"><div><span className="lc-eyebrow">CONTRAT SAISI PAR ERREUR</span><h3 id="loan-contract-cancellation-title">Annuler ce dossier de crédit</h3></div><button type="button" className="text-button" disabled={busy} onClick={onClose}>Garder le contrat</button></div>
  <p className="lc-note">{file.data.lender} · Contrat {file.data.reference} · {file.data.plate}</p>
  {blocked?<div className="lc-cancellation-blocked" role="status"><strong>Annulation bloquée</strong>{file.data.opening_paid_centimes>0&&<p><b>{money(file.data.opening_paid_centimes)}</b> de règlements antérieurs au suivi sont enregistrés. Vérifiez leur situation ; ils ne peuvent pas être effacés par cette annulation.</p>}{file.data.recorded_paid_centimes>0&&<p><b>{money(file.data.recorded_paid_centimes)}</b> de paiements sont encore comptabilisés. Annulez uniquement les saisies erronées dans l’historique des paiements. Conservez tout paiement réellement effectué.</p>}</div>:<>
   <p className="lc-note">Le contrat sortira du suivi, des totaux et des alertes. Son tableau, ses documents et son historique resteront consultables. Cette action n’annule pas un engagement réel auprès de la banque.</p>
   {error&&<p className="lc-alert" role="alert">{error}</p>}
   <Field name="reason" help="loan_contract_cancel_reason" label="Pourquoi annuler ce contrat ?" required maxLength={500} placeholder="Ex. : dossier saisi pour la mauvaise voiture" disabled={busy} value={reason} error={errors.reason} onChange={e=>{setReason(e.target.value);setErrors(prev=>({...prev,reason:''}));}}/>
   <div className="lc-table-wrap"><table className="lc-table lc-correction-preview"><thead><tr><th>Impact sur le suivi</th><th>Avant</th><th>Après annulation</th></tr></thead><tbody><tr><td>Reste comptabilisé pour ce contrat</td><td>{money(file.data.remaining_centimes)}</td><td><strong>0,00 DH</strong></td></tr><tr><td>Échéances à suivre</td><td>{file.data.remaining_count}</td><td><strong>0</strong></td></tr><tr><td>Historique et documents</td><td>Conservés</td><td><strong>Conservés</strong></td></tr></tbody></table></div>
   <Check name="confirmed" checked={confirmed} onChange={setConfirmed} error={errors.confirmed}>Ce dossier a été créé par erreur. Je confirme son retrait du suivi et sa conservation dans les contrats annulés.</Check>
  </>}
  <button type="button" className="primary lc-cancel-confirm" disabled={busy||blocked} onClick={save}>{busy?'Enregistrement…':'Confirmer l’annulation du contrat'}</button>
 </section>;
}
