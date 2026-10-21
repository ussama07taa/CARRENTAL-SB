'use client';
import {useRef,useState} from 'react';
import {apiMutation} from '../_lib/auth-client';
import {Field,Check,focusError,responseErrors,type Errors} from './loan-controls';
import {cents,date,money,type LoanFile,type LoanPayment} from './loan-types';
export default function LoanPaymentCorrection({file,payment,onClose,onSaved,onStale,onBusy}:{file:LoanFile;payment:LoanPayment;onClose:()=>void;onSaved:()=>void;onStale:(message:string)=>void;onBusy:(busy:boolean)=>void}){
 const root=useRef<HTMLElement>(null),lock=useRef(false),key=useRef(crypto.randomUUID());
 const [amount,setAmount]=useState(String(payment.effective_amount_centimes/100)),[reason,setReason]=useState(''),[confirmed,setConfirmed]=useState(false),[errors,setErrors]=useState<Errors>({}),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const installment=file.installments.find(i=>i.id===payment.installment_id)!;
 const current=payment.effective_amount_centimes,maximum=current+installment.remaining_centimes;
 let corrected:number|null=null;try{corrected=cents(amount);}catch{}
 const valid=corrected!==null&&corrected>0&&corrected<=maximum;
 async function save(){
  if(lock.current)return;const fields:Errors={};
  if(!valid)fields.amount_centimes='Saisissez le montant correct, entre 0,01 DH et '+money(maximum)+'.';
  else if(corrected===current)fields.amount_centimes='Ce montant est identique au montant actuellement retenu.';
  if(!reason.trim())fields.reason='Expliquez l’erreur de saisie.';
  if(!confirmed)fields.confirmed='Confirmez qu’il s’agit d’une correction de saisie.';
  setErrors(fields);if(Object.keys(fields).length){focusError(root.current,fields);return;}
  lock.current=true;setBusy(true);onBusy(true);setError('');
  try{
   const response=await apiMutation(`loans/${file.data.id}/payments/${payment.id}/corrections`,'POST',{amount_centimes:corrected,reason:reason.trim(),confirmed:true,correction_revision:payment.correction_revision,financial_revision:file.financial_revision,expected_remaining_centimes:installment.remaining_centimes,expected_loan_remaining_centimes:file.data.remaining_centimes,request_key:key.current});
   const body=await response.json();
   if(response.status===401){window.location.replace('/login');return;}
   if(response.status===409){onStale(body.message??'Le dossier a changé. Vérifiez le nouveau calcul.');return;}
   if(!response.ok){const fields=responseErrors(body);setErrors(fields);focusError(root.current,fields);if(response.status===422)key.current=crypto.randomUUID();throw Error(body.message??'Correction non enregistrée.');}
   onSaved();
  }catch(e){setError((e as Error).message);}finally{lock.current=false;setBusy(false);onBusy(false);}
 }
 return <section className="lc-correction" id="loan-correction" ref={root} aria-labelledby="loan-correction-title">
  <div className="lc-section-title"><div><span className="lc-eyebrow">CORRECTION DE SAISIE</span><h3 id="loan-correction-title">Paiement du {date(payment.paid_on)} · échéance {payment.position}</h3></div><button type="button" className="text-button" disabled={busy} onClick={onClose}>Fermer sans corriger</button></div>
  <p className="lc-note">Saisissez le montant réellement payé, <strong>pas la différence</strong>. L’enregistrement d’origine reste conservé. Aucun remboursement ni nouveau paiement n’est créé.</p>
  {error&&<p className="lc-alert" role="alert">{error}</p>}
  <div className="lc-grid"><Field name="amount_centimes" help="loan_correct_amount" label="Montant correct du paiement · DH" required inputMode="decimal" value={amount} disabled={busy} error={errors.amount_centimes} onChange={e=>{setAmount(e.target.value);setConfirmed(false);setErrors(prev=>({...prev,amount_centimes:''}));}}/><Field name="reason" help="loan_correct_reason" label="Pourquoi corriger ce paiement ?" required maxLength={500} placeholder="Ex. : un zéro en trop" disabled={busy} value={reason} error={errors.reason} onChange={e=>{setReason(e.target.value);setErrors(prev=>({...prev,reason:''}));}}/></div>
  <div className="lc-table-wrap"><table className="lc-table lc-correction-preview"><thead><tr><th>Vérifier le calcul</th><th>Avant</th><th>Après correction</th></tr></thead><tbody>
   <tr><td>Ce paiement</td><td>{money(current)}</td><td><strong>{valid?money(corrected!):'À vérifier'}</strong></td></tr>
   <tr><td>Reste sur l’échéance {installment.position}</td><td>{money(installment.remaining_centimes)}</td><td><strong>{valid?money(installment.remaining_centimes+current-corrected!):'À vérifier'}</strong></td></tr>
   <tr><td>Reste sur tout le crédit</td><td>{money(file.data.remaining_centimes)}</td><td><strong>{valid?money(file.data.remaining_centimes+current-corrected!):'À vérifier'}</strong></td></tr>
  </tbody></table></div>
  {valid&&installment.remaining_centimes===0&&corrected!<current&&<p className="lc-note">Cette échéance redeviendra partiellement réglée. Son solde apparaîtra à nouveau dans les échéances à payer.</p>}
  <Check name="confirmed" checked={confirmed} onChange={setConfirmed} error={errors.confirmed}>Je confirme le montant correct. Il s’agit d’une erreur de saisie, pas d’un remboursement reçu.</Check>
  <button type="button" className="primary" disabled={busy} onClick={save}>{busy?'Enregistrement…':'Confirmer la correction'}</button>
 </section>;
}
