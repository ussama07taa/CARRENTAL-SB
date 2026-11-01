'use client';
import {useEffect,useRef,useState} from 'react';
import {apiMutation} from '../_lib/auth-client';
import {Check,Field,responseErrors,type Errors} from './loan-controls';
import {cents,date,money,paymentMethods,type Purchase,type PurchasePayment} from './loan-types';

export default function PurchasePanel({loanId,purchase,today,tracking,cancelled,initialPay=false,onChanged,onBusy}:{loanId:number;purchase:Purchase;today:string;tracking:string;cancelled:boolean;initialPay?:boolean;onChanged:()=>void;onBusy:(v:boolean)=>void}){
 const [editing,setEditing]=useState<PurchasePayment|null|undefined>(undefined),[amount,setAmount]=useState(''),[paidOn,setPaidOn]=useState(today),[method,setMethod]=useState('transfer'),[reference,setReference]=useState(''),[reason,setReason]=useState(''),[confirmed,setConfirmed]=useState(false),[busy,setBusy]=useState(false),[uncertain,setUncertain]=useState(false),[errors,setErrors]=useState<Errors>({}),[error,setError]=useState('');
 const lock=useRef(false),key=useRef(crypto.randomUUID()),pending=useRef<{url:string;body:Record<string,unknown>}|null>(null),opened=useRef(false),section=useRef<HTMLElement>(null);
 const p=purchase.data;
 function begin(payment:PurchasePayment|null){
  if(cancelled||busy||uncertain)return;setEditing(payment);setAmount(String((payment?.amount_centimes??p.remaining_centimes)/100));setPaidOn(payment?.paid_on??today);setMethod(payment?.method??'transfer');setReference(payment?.reference??'');setReason('');setConfirmed(false);setErrors({});setError('');key.current=crypto.randomUUID();onBusy(true);
  requestAnimationFrame(()=>section.current?.scrollIntoView({block:'start',behavior:'smooth'}));
 }
 useEffect(()=>{if(initialPay&&!opened.current&&p.remaining_centimes>0){opened.current=true;begin(null)}},[initialPay]);
 function cancel(){if(busy||uncertain)return;setEditing(undefined);onBusy(false);setError('')}
 async function save(){
  if(lock.current)return;
  if(!pending.current){
   const fields:Errors={};let value=0;
   try{value=cents(amount);if(!editing&&value===0)throw Error('Indiquez un montant positif.');if(value>p.remaining_centimes+(editing?.amount_centimes??0))throw Error('Le montant dépasse le reste de l’apport.')}catch(e){fields.amount_centimes=(e as Error).message}
   if(!paidOn||paidOn< tracking||paidOn<p.purchased_on||paidOn>today)fields.paid_on='Vérifiez la date du versement.';
   if(editing&&!reason.trim())fields.reason='Expliquez la correction.';
   if(!confirmed)fields.receipt_confirmed='Confirmez les informations du versement.';
   if(Object.keys(fields).length){setErrors(fields);return}
   pending.current={url:'loans/'+loanId+'/purchase-payments'+(editing?'/'+editing.id:''),body:{amount_centimes:value,paid_on:paidOn,method,reference:reference.trim()||null,reason:reason.trim()||null,expected_remaining_centimes:p.remaining_centimes,expected_version:editing?.version,receipt_confirmed:true,request_key:key.current}};
  }
  lock.current=true;setBusy(true);setError('');
  try{
   const r=await apiMutation(pending.current.url,'POST',pending.current.body),body=await r.json();
   if(r.status===401){location.replace('/login');return}
   if(!r.ok){
    if(r.status<500){pending.current=null;setUncertain(false);key.current=crypto.randomUUID();setErrors(responseErrors(body));if(r.status===409){setEditing(undefined);onBusy(false);onChanged()}}
    else setUncertain(true);
    throw Error(body.message??'Enregistrement impossible.');
   }
   pending.current=null;setUncertain(false);setEditing(undefined);onBusy(false);onChanged();
  }catch(e){if(pending.current)setUncertain(true);setError(e instanceof Error?e.message:'Connexion interrompue.')}
  finally{lock.current=false;setBusy(false)}
 }
 return <section className="lc-section lc-purchase" ref={section} aria-label="Achat et apport initial">
 <div className="lc-section-title"><div><span className="lc-eyebrow">L’ACHAT DE LA VOITURE</span><h3>{p.seller}</h3><p className="lc-note">Achat du {date(p.purchased_on)}</p></div><span className={'lc-badge '+(p.remaining_centimes?'scheduled':'paid')}>{cancelled?'Historique conservé':p.down_payment_centimes===0?'Sans apport':p.remaining_centimes?'Apport à régler':'Apport réglé'}</span></div>
 <p className="lc-purchase-agreement">Prix d’achat : <strong>{money(p.price_centimes)}</strong> · Apport prévu : <strong>{money(p.down_payment_centimes)}</strong> · Montant financé : <strong>{money(p.price_centimes-p.down_payment_centimes)}</strong></p>
 <div className="lc-purchase-action"><div><strong>Apport : {money(p.paid_centimes)} versés</strong><p>{p.remaining_centimes?money(p.remaining_centimes)+' à régler · '+date(p.down_payment_due_on):p.down_payment_centimes===0?'Aucun apport prévu pour cet achat.':'Le versement de l’apport est enregistré.'}</p></div>{!cancelled&&p.remaining_centimes>0&&editing===undefined&&<button className="primary" onClick={()=>begin(null)}>Enregistrer le paiement de l’apport</button>}</div>
 <p className="lc-note">Le prix d’achat ne crée pas de mouvement de caisse. L’apport et chaque mensualité sont comptés à leur paiement, selon le moyen utilisé.</p>
 {error&&<p className="lc-alert" role="alert">{error}</p>}
 {editing!==undefined&&<div className="lc-purchase-form"><h3>{editing?'Corriger le versement de l’apport':'Paiement de l’apport initial'}</h3>{uncertain&&<p className="lc-alert">Réponse non confirmée. Réessayez le même paiement pour retrouver son résultat sans doublon.</p>}
 <fieldset className="lc-form-fields" disabled={busy||uncertain}><div className="lc-grid"><Field name="amount_centimes" label="Montant versé · DH" required inputMode="decimal" value={amount} onChange={e=>{setAmount(e.target.value);setConfirmed(false)}} error={errors.amount_centimes}/><Field name="paid_on" label="Date du versement" required type="date" min={tracking>p.purchased_on?tracking:p.purchased_on} max={today} value={paidOn} onChange={e=>{setPaidOn(e.target.value);setConfirmed(false)}} error={errors.paid_on}/><label className="lc-field">Moyen du versement<select data-validation-field="method" value={method} onChange={e=>{setMethod(e.target.value);setConfirmed(false)}}>{Object.entries(paymentMethods).map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label><Field name="reference" label="Référence du versement · facultatif" value={reference} maxLength={150} onChange={e=>setReference(e.target.value)} error={errors.reference}/></div>
 {editing&&<><Field name="reason" label="Motif de la correction" required value={reason} maxLength={1000} onChange={e=>setReason(e.target.value)} error={errors.reason}/><p className="lc-note">L’original reste conservé. Mettre 0 annule uniquement une saisie erronée ; cela n’enregistre pas un remboursement du vendeur.</p></>}
 <Check name="receipt_confirmed" checked={confirmed} onChange={setConfirmed} error={errors.receipt_confirmed}>Je confirme le versement réellement effectué et son moyen de paiement.</Check></fieldset>
 <div className="lc-purchase-buttons"><button className="secondary" onClick={cancel} disabled={busy||uncertain}>Annuler la saisie</button><button className="primary" disabled={busy} onClick={save}>{busy?'Enregistrement…':uncertain?'Réessayer le même paiement':editing?'Enregistrer la correction':'Enregistrer le versement'}</button></div></div>}
 {purchase.payments.length>0&&<details className="lc-options"><summary>Versements de l’apport · {purchase.payments.filter(x=>x.amount_centimes>0).length}</summary>{purchase.payments.map(payment=><div className="lc-purchase-history" key={payment.payment_key}><div><strong>{money(payment.amount_centimes)}</strong><p>{date(payment.paid_on)} · {paymentMethods[payment.method]}{payment.reference?' · '+payment.reference:''}</p><small>{payment.version>1?'Corrigé · version '+payment.version:'Versement enregistré'}</small></div>{!cancelled&&editing===undefined&&<button className="secondary" onClick={()=>begin(payment)}>Corriger ce versement</button>}</div>)}<details><summary>Historique conservé</summary>{purchase.history.map(h=><p key={h.id}>V{h.version} · {money(h.amount_centimes)} · {date(h.paid_on)} · {paymentMethods[h.method]} · {h.actor}{h.reason?' · '+h.reason:''}</p>)}</details></details>}
 </section>
}
