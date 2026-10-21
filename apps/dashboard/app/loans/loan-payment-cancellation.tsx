'use client';
import {useRef,useState} from 'react';
import {apiMutation} from '../_lib/auth-client';
import {Field,Check,focusError,responseErrors,type Errors} from './loan-controls';
import {date,money,paymentMethods,type LoanFile,type LoanPayment} from './loan-types';
export default function LoanPaymentCancellation({file,payment,onClose,onSaved,onStale,onBusy}:{file:LoanFile;payment:LoanPayment;onClose:()=>void;onSaved:()=>void;onStale:(message:string)=>void;onBusy:(busy:boolean)=>void}){
 const root=useRef<HTMLElement>(null),lock=useRef(false),key=useRef(crypto.randomUUID());
 const [reason,setReason]=useState(''),[confirmed,setConfirmed]=useState(false),[errors,setErrors]=useState<Errors>({}),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const installment=file.installments.find(i=>i.id===payment.installment_id)!;
 const amount=payment.effective_amount_centimes;
 async function save(){
  if(lock.current)return;const fields:Errors={};
  if(!reason.trim())fields.reason='Expliquez pourquoi cet enregistrement est un doublon ou une erreur.';
  if(!confirmed)fields.confirmed='Confirmez qu’il s’agit d’un enregistrement erroné.';
  setErrors(fields);if(Object.keys(fields).length){focusError(root.current,fields);return;}
  lock.current=true;setBusy(true);onBusy(true);setError('');
  try{
   const response=await apiMutation(`loans/${file.data.id}/payments/${payment.id}/cancellations`,'POST',{reason:reason.trim(),confirmed:true,correction_revision:payment.correction_revision,financial_revision:file.financial_revision,expected_remaining_centimes:installment.remaining_centimes,expected_loan_remaining_centimes:file.data.remaining_centimes,request_key:key.current});
   const body=await response.json();
   if(response.status===401){window.location.replace('/login');return;}
   if(response.status===409){onStale(body.message??'Le dossier a changé. Vérifiez le nouveau calcul.');return;}
   if(!response.ok){const fields=responseErrors(body);setErrors(fields);focusError(root.current,fields);if(response.status===422)key.current=crypto.randomUUID();throw Error(body.message??'Annulation non enregistrée.');}
   onSaved();
  }catch(e){setError((e as Error).message);}finally{lock.current=false;setBusy(false);onBusy(false);}
 }
 return <section className="lc-correction lc-cancellation" id="loan-cancellation" ref={root} aria-labelledby="loan-cancellation-title">
  <div className="lc-section-title"><div><span className="lc-eyebrow">DOUBLON OU ERREUR DE SAISIE</span><h3 id="loan-cancellation-title">Annuler cet enregistrement</h3></div><button type="button" className="text-button" disabled={busy} onClick={onClose}>Garder le paiement</button></div>
  <div className="lc-cancellation-target"><strong>{money(amount)}</strong><span>{date(payment.paid_on)} · échéance {payment.position}<small>{paymentMethods[payment.method]} · Référence : {payment.reference||'—'} · Enregistrement n° {payment.id}</small></span></div>
  <p className="lc-note">Annulez uniquement le doublon ou la saisie erronée. Si vous avez réellement payé deux fois, les deux paiements doivent rester enregistrés. Cette action ne constate aucun remboursement.</p>
  {error&&<p className="lc-alert" role="alert">{error}</p>}
  <Field name="reason" help="loan_cancel_reason" label="Pourquoi annuler cet enregistrement ?" required maxLength={500} placeholder="Ex. : le même versement de 500 DH a été saisi deux fois" disabled={busy} value={reason} error={errors.reason} onChange={e=>{setReason(e.target.value);setErrors(prev=>({...prev,reason:''}));}}/>
  <div className="lc-table-wrap"><table className="lc-table lc-correction-preview"><thead><tr><th>Vérifier le calcul</th><th>Avant</th><th>Après annulation</th></tr></thead><tbody>
   <tr><td>Réglé sur l’échéance {installment.position}</td><td>{money(installment.settled_centimes)}</td><td><strong>{money(installment.settled_centimes-amount)}</strong></td></tr>
   <tr><td>Reste sur l’échéance {installment.position}</td><td>{money(installment.remaining_centimes)}</td><td><strong>{money(installment.remaining_centimes+amount)}</strong></td></tr>
   <tr><td>Reste sur tout le crédit</td><td>{money(file.data.remaining_centimes)}</td><td><strong>{money(file.data.remaining_centimes+amount)}</strong></td></tr>
  </tbody></table></div>
  <Check name="confirmed" checked={confirmed} onChange={setConfirmed} error={errors.confirmed}>Ce paiement a été enregistré par erreur ou en double. Je confirme l’annulation de cet enregistrement uniquement.</Check>
  <p className="lc-note">L’original, les corrections éventuelles, le motif et l’auteur de l’annulation restent dans l’historique.</p>
  <button type="button" className="primary lc-cancel-confirm" disabled={busy} onClick={save}>{busy?'Enregistrement…':'Confirmer l’annulation'}</button>
 </section>;
}
