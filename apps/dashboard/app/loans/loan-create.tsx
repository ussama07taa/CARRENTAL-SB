'use client';
import PurchaseBalance from './purchase-balance';
import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {apiMutation} from '../_lib/auth-client';
import {Check,Field,focusError,responseErrors,type Errors} from './loan-controls';
import LoanVehiclePicker from './loan-vehicle-picker';
import LoanSchedule from './loan-schedule';
import {cents,date,money,monthDate,type VehicleChoice} from './loan-types';
import type {ScheduleRow} from './loan-schedule';
import {splitFinancing,inputMoney,type LoanSplit} from './loan-calculation';
type Row=ScheduleRow;
export default function LoanCreate({today,initialVehicle,onClose,onSaved,purchaseAvailable=false}:{purchaseAvailable?:boolean;today:string;initialVehicle:VehicleChoice|null;onClose:()=>void;onSaved:(id:number)=>void}){
 const dialog=useRef<HTMLDialogElement>(null),lock=useRef(false),key=useRef(crypto.randomUUID());
 const [step,setStep]=useState(0),[vehicle,setVehicle]=useState(initialVehicle),[lender,setLender]=useState(''),[reference,setReference]=useState(''),[signed,setSigned]=useState(''),[tracking,setTracking]=useState(today),[financed,setFinanced]=useState(''),[file,setFile]=useState<File|null>(null),[amount,setAmount]=useState(''),[count,setCount]=useState('24'),[first,setFirst]=useState(today),[manualRows,setRows]=useState<Row[]>([]),[past,setPast]=useState('0'),[scheduleOK,setScheduleOK]=useState(false),[openingOK,setOpeningOK]=useState(false),[errors,setErrors]=useState<Errors>({}),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const [mode,setMode]=useState<'new'|'existing'>('new'),[partial,setPartial]=useState('0'),[generatedFrom,setGeneratedFrom]=useState(''),[editing,setEditing]=useState<number|null>(null),[tablePage,setTablePage]=useState(0),[customized,setCustomized]=useState(false);
 const [withPurchase,setWithPurchase]=useState(purchaseAvailable),[seller,setSeller]=useState(''),[purchasePrice,setPurchasePrice]=useState(''),[downPayment,setDownPayment]=useState(''),[purchasedOn,setPurchasedOn]=useState(today),[downDue,setDownDue]=useState(today),[uncertain,setUncertain]=useState(false);
 const [scheduleMode,setScheduleMode]=useState<'automatic'|'bank'>('automatic'),[calculationBasis,setCalculationBasis]=useState<'count'|'amount'>('count');
 const pending=useRef<FormData|null>(null);
 const buying=withPurchase&&mode==='new';
 const automatic=buying&&scheduleMode==='automatic';
 let financedAmount=0,plan:LoanSplit|null=null,calculationError='';
 if(automatic){
  try{
   financedAmount=cents(purchasePrice)-cents(downPayment||'0');
   const value=calculationBasis==='count'?(/^\d+$/.test(count)?Number(count):NaN):cents(amount);
   plan=splitFinancing(financedAmount,calculationBasis,value);
  }catch(x){calculationError=(x as Error).message;}
 }
 const effectiveAmount=automatic&&calculationBasis==='count'?(plan?inputMoney(plan.regular):''):amount;
 const effectiveCount=automatic&&calculationBasis==='amount'?(plan?String(plan.count):''):count;
 const validFirst=/^\d{4}-\d{2}-\d{2}$/.test(first)&&first>='1900-01-01'&&first<='2170-01-01'&&(!signed||first>=signed);
 const rows:Row[]=automatic?(plan&&validFirst?plan.amounts.map((v,i)=>({due:monthDate(first,i),amount:inputMoney(v),opening:'0'})):[]):manualRows;
 const fingerprint=JSON.stringify([amount,count,first,mode,past,partial]);
 const dirty=!!(lender||reference||rows.length||file||seller||purchasePrice||downPayment);
 useEffect(()=>{const origin=document.activeElement as HTMLElement;dialog.current?.showModal();return()=>origin?.focus();},[]);
 useEffect(()=>{const prevent=(e:BeforeUnloadEvent)=>{if(dirty){e.preventDefault();}};window.addEventListener('beforeunload',prevent);return()=>window.removeEventListener('beforeunload',prevent);},[dirty]);
 function close(){if(!lock.current&&!uncertain&&(!dirty||window.confirm('Quitter sans enregistrer ce contrat ?')))onClose();}
 function fail(e:Errors,s=step){
  setErrors(e);setStep(s);
  const key=Object.keys(e).find(k=>/^installments\.\d+\./.test(k));
  if(key){const i=Number(key.split('.')[1]);setEditing(i);setTablePage(Math.floor(i/12));}
  focusError(dialog.current,e);
 }
 function validate(s:number){const e:Errors={};if(s===0){if(!vehicle)e.vehicle_id='Choisissez une voiture.';if(!lender.trim())e.lender='Indiquez l’organisme de financement.';if(!reference.trim())e.reference='Indiquez le numéro du contrat.';if(!tracking||tracking>today)e.tracking_from='Choisissez une date au plus tard aujourd’hui.';if(signed&&signed>today)e.signed_on='La date du contrat ne peut pas être future.';if(financed&&!buying){try{if(cents(financed)<=0)throw Error('Indiquez un montant positif.');}catch(x){e.financed_centimes=(x as Error).message;}}if(buying){if(!seller.trim())e['purchase.seller']='Indiquez le vendeur.';try{const price=cents(purchasePrice),apport=cents(downPayment||'0');if(price<=0||apport>=price)throw Error('Le prix doit être positif et supérieur à l’apport.');}catch(x){e['purchase.price_centimes']=(x as Error).message;}if(!purchasedOn||purchasedOn>today)e['purchase.purchased_on']='Vérifiez la date de l’achat.';if(!downDue||downDue<purchasedOn)e['purchase.down_payment_due_on']='La date ne peut pas précéder l’achat.';}
 if(file&&(file.size>5*1024*1024||!['application/pdf','image/jpeg','image/png','image/webp'].includes(file.type)))e.file='Choisissez un PDF ou une image JPG, PNG ou WebP de 5 Mo maximum.';}
 if(s===1){if(automatic&&calculationError)e[calculationBasis==='count'?'generator_count':'generator_amount']=calculationError;if(automatic&&!validFirst)e.generator_first='Vérifiez la première échéance et la date du contrat.';if(!rows.length&&!automatic)e.generator='Préparez le tableau des échéances.';else if(!automatic&&generatedFrom!==fingerprint)e.generator='Les paramètres ont changé. Actualisez le tableau avant de continuer.';rows.forEach((r,i)=>{if(!r.due||r.due<'1900-01-01'||r.due>'2199-12-31'||(i>0&&r.due<rows[i-1].due)||(signed&&r.due<signed))e[`installments.${i}.due_on`]='Vérifiez la date et l’ordre des échéances.';let a=0;try{a=cents(r.amount);if(a<=0)throw Error('Le montant doit être supérieur à 0.');}catch(x){e[`installments.${i}.amount_centimes`]=(x as Error).message;}try{if(cents(r.opening||'0')>a)throw Error('Le montant déjà réglé dépasse cette échéance.');}catch(x){e[`installments.${i}.opening_paid_centimes`]=(x as Error).message;}});}
 if(Object.keys(e).length){fail(e,s);return false;}setErrors({});return true;}
 function chooseMode(next:'new'|'existing'){
  if(next===mode)return;
  if(next==='new'&&rows.some(r=>r.opening!==''&&r.opening!=='0')&&!window.confirm('Passer à un nouveau crédit remet les anciens règlements à zéro. Continuer ?'))return;
  setMode(next);setWithPurchase(next==='new'&&purchaseAvailable);setPast('0');setPartial('0');setRows(rows.map(r=>({...r,opening:'0'})));
  if(rows.length&&generatedFrom===fingerprint)setGeneratedFrom(JSON.stringify([amount,count,first,next,'0','0']));
  setOpeningOK(false);setScheduleOK(false);setErrors({});setEditing(null);
 }
 function clearFieldError(name:string){setErrors(current=>{const next={...current};delete next[name];return next;});}
 function generate(){
  const e:Errors={};let installmentAmount=0,paidPart=0;
  try{installmentAmount=cents(amount);if(installmentAmount<=0)throw Error('Indiquez le montant d’un versement.');}catch(x){e.generator_amount=(x as Error).message;}
  if(!/^\d+$/.test(count)||Number(count)<1||Number(count)>360)e.generator_count='Entre 1 et 360 échéances.';
  if(!first||first<'1900-01-01'||first>'2170-01-01')e.generator_first='Indiquez une date valide (avant 2170).';
  if(signed&&first<signed)e.generator_first='La première échéance ne peut pas précéder la date du contrat.';
  if(mode==='existing'){
   if(!/^\d+$/.test(past)||Number(past)>Number(count))e.past='Indiquez un nombre entre 0 et '+count+'.';
   try{paidPart=cents(partial||'0');if(paidPart>installmentAmount)throw Error('La partie déjà réglée dépasse le montant d’une échéance.');if(Number(past)===Number(count)&&paidPart>0)throw Error('Toutes les échéances sont réglées : laissez ce montant à 0.');}catch(x){e.loan_partial_opening=(x as Error).message;}
  }
  if(Object.keys(e).length){fail(e);return;}
  if(rows.length&&customized&&!window.confirm('Refaire le tableau remplacera vos ajustements de dates et de montants. Continuer ?'))return;
  setRows(Array.from({length:Number(count)},(_,i)=>({due:monthDate(first,i),amount,opening:mode==='existing'?(i<Number(past)?amount:i===Number(past)?partial||'0':'0'):'0'})));
  setGeneratedFrom(fingerprint);setCustomized(false);setEditing(null);setTablePage(0);setErrors({});setOpeningOK(false);setScheduleOK(false);
  requestAnimationFrame(()=>dialog.current?.querySelector('#loan-prepared-table')?.scrollIntoView({block:'start',behavior:'smooth'}));
 }
 function chooseSchedule(next:'automatic'|'bank'){
  if(next===scheduleMode)return;
  if(next==='automatic'&&customized&&!window.confirm('Recalculer remplacera les montants et dates ajustés du tableau. Continuer ?'))return;
  if(next==='bank'&&automatic){
   setAmount(effectiveAmount);setCount(effectiveCount);setRows(rows);
   setGeneratedFrom(JSON.stringify([effectiveAmount,effectiveCount,first,mode,past,partial]));
  }
  setScheduleMode(next);setCustomized(false);setEditing(null);setTablePage(0);setErrors({});setOpeningOK(false);setScheduleOK(false);
 }
 function changeGenerator(field:'count'|'amount'|'first',value:string){
  if(field==='count'){setCount(value);if(automatic)setCalculationBasis('count');}
  else if(field==='amount'){setAmount(value);if(automatic)setCalculationBasis('amount');}
  else setFirst(value);
  clearFieldError('generator_'+field);if(automatic){clearFieldError('generator_count');clearFieldError('generator_amount');}clearFieldError('generator');setEditing(null);setTablePage(0);setScheduleOK(false);
 }
 function changeRow(i:number,field:keyof Row,value:string){
  if(automatic){
   setScheduleMode('bank');setAmount(effectiveAmount);setCount(effectiveCount);
   setGeneratedFrom(JSON.stringify([effectiveAmount,effectiveCount,first,mode,past,partial]));
  }
  setRows(rows.map((r,j)=>i===j?{...r,[field]:value}:r));clearFieldError(`installments.${i}.${{due:'due_on',amount:'amount_centimes',opening:'opening_paid_centimes'}[field]}`);setCustomized(true);setScheduleOK(false);setOpeningOK(false);
 }
 const safe=(v:string)=>{try{return cents(v||'0');}catch{return 0;}};
 const total=rows.reduce((s,r)=>s+safe(r.amount),0),opening=rows.reduce((s,r)=>s+safe(r.opening),0);
 async function save(){if(lock.current)return;if(!uncertain&&(!validate(0)||!validate(1)))return;const e:Errors={};if(!scheduleOK)e.schedule_confirmed='Vérifiez le tableau puis cochez cette confirmation.';if(opening>0&&!openingOK)e.opening_confirmed='Confirmez les montants réglés avant le suivi.';if(Object.keys(e).length){fail(e,2);return;}lock.current=true;setBusy(true);setError('');try{const data=pending.current??new FormData();if(!pending.current){data.set('vehicle_id',String(vehicle!.id));data.set('lender',lender.trim());data.set('reference',reference.trim());data.set('tracking_from',tracking);if(signed)data.set('signed_on',signed);if(buying){data.set('purchase',JSON.stringify({seller:seller.trim(),purchased_on:purchasedOn,down_payment_due_on:downDue,price_centimes:cents(purchasePrice),down_payment_centimes:cents(downPayment||'0')}));data.set('financed_centimes',String(cents(purchasePrice)-cents(downPayment||'0')));}else if(financed)data.set('financed_centimes',String(cents(financed)));data.set('installments',JSON.stringify(rows.map(r=>({due_on:r.due,amount_centimes:cents(r.amount),opening_paid_centimes:cents(r.opening||'0')}))));data.set('schedule_confirmed','1');data.set('opening_confirmed',openingOK?'1':'0');data.set('request_key',key.current);if(file)data.set('file',file);pending.current=data;}const response=await apiMutation('loans','POST',data);const body=await response.json();if(response.status===401){window.location.replace('/login');return;}if(!response.ok){if(response.status<500){pending.current=null;setUncertain(false);}else{setUncertain(true);}const fields=responseErrors(body);const target=Object.keys(fields).some(k=>k.startsWith('purchase')||['vehicle_id','lender','reference','tracking_from','signed_on','file','financed_centimes'].includes(k))?0:Object.keys(fields).some(k=>k.startsWith('installments'))?1:2;fail(fields,target);if(response.status===422)key.current=crypto.randomUUID();throw Error(body.message??'Enregistrement impossible.');}pending.current=null;setUncertain(false);onSaved(body.id);}catch(x){if(pending.current)setUncertain(true);setError((x as Error).message);}finally{lock.current=false;setBusy(false);}}
 return createPortal(<dialog ref={dialog} className="lc-dialog" aria-labelledby="loan-create-title" onCancel={e=>{e.preventDefault();close();}}><header className="lc-dialog-head"><div><span className="lc-eyebrow">ACHAT & FINANCEMENT</span><h2 id="loan-create-title">Votre voiture, son achat et son crédit</h2><p>L’apport et les mensualités restent dans le même dossier.</p></div><button type="button" className="lc-close" aria-label="Fermer" onClick={close} disabled={busy||uncertain}>×</button></header><ol className="lc-steps">{['La voiture & l’achat','Les mensualités','Vérifier'].map((title,i)=><li key={title} aria-current={step===i?'step':undefined}><b>{i+1}</b>{title}</li>)}</ol><div className="lc-dialog-body">{error&&<p className="lc-alert" role="alert">{error}</p>}
 {uncertain&&<p className="lc-alert" role="alert">La réponse n’est pas confirmée. Réessayez le même enregistrement ; il ne sera pas créé deux fois.</p>}<fieldset className="lc-form-fields" disabled={busy||uncertain}>{step===0&&<><section className="lc-mode-choice" aria-label="Type de crédit"><button type="button" aria-pressed={mode==='new'} onClick={()=>chooseMode('new')}><span className="lc-mode-icon" aria-hidden="true">＋</span><span><strong>{purchaseAvailable?'Achat avec crédit':'Nouveau crédit'}</strong><small>Une nouvelle voiture, tout commence à zéro.</small></span><span className="lc-mode-tick" aria-hidden="true">{mode==='new'?'✓':''}</span></button><button type="button" aria-pressed={mode==='existing'} onClick={()=>chooseMode('existing')}><span className="lc-mode-icon" aria-hidden="true">↶</span><span><strong>Crédit déjà en cours</strong><small>Reprendre les échéances déjà payées.</small></span><span className="lc-mode-tick" aria-hidden="true">{mode==='existing'?'✓':''}</span></button></section><section className="lc-section"><h3>1. Quelle voiture ?</h3><LoanVehiclePicker selected={vehicle} onSelect={setVehicle}/>{errors.vehicle_id&&<p className="lc-error">{errors.vehicle_id}</p>}</section>{mode==='new'&&purchaseAvailable&&<section className="lc-section"><div className="lc-section-title"><h3>2. L’achat de la voiture</h3><label><input data-validation-field="with_purchase" type="checkbox" checked={withPurchase} onChange={e=>setWithPurchase(e.target.checked)}/> Inclure l’achat</label></div>{buying?<><div className="lc-grid"><Field name="purchase.seller" label="Vendeur / concessionnaire" required value={seller} onChange={e=>setSeller(e.target.value)} error={errors['purchase.seller']}/><Field name="purchase.price_centimes" label="Prix d’achat · DH" required inputMode="decimal" placeholder="200000" value={purchasePrice} onChange={e=>setPurchasePrice(e.target.value)} error={errors['purchase.price_centimes']}/><Field name="purchase.down_payment_centimes" label="Apport initial prévu · DH" required inputMode="decimal" placeholder="50000" value={downPayment} onChange={e=>setDownPayment(e.target.value)} error={errors['purchase.down_payment_centimes']}/><div className="lc-purchase-financed"><span>Montant financé</span><strong>{money(Math.max(0,safe(purchasePrice)-safe(downPayment)))}</strong><small>Prix d’achat − apport initial</small></div></div><details className="lc-options"><summary>Dates de l’achat et de l’apport · {date(purchasedOn)}</summary><div className="lc-grid"><Field name="purchase.purchased_on" label="Date de l’achat" type="date" max={today} value={purchasedOn} onChange={e=>{setPurchasedOn(e.target.value);if(downDue<e.target.value)setDownDue(e.target.value)}} error={errors['purchase.purchased_on']}/><Field name="purchase.down_payment_due_on" label="Apport à régler le" type="date" min={purchasedOn} value={downDue} onChange={e=>setDownDue(e.target.value)} error={errors['purchase.down_payment_due_on']}/></div></details><p className="lc-note">L’apport est prévu. Vous enregistrerez son paiement depuis ce dossier lorsqu’il aura été versé.</p></>:<p className="lc-note">Seul le contrat de crédit sera enregistré.</p>}</section>}<section className="lc-section"><h3>{buying?'3. Le financement':'2. Le contrat'}</h3><div className="lc-grid"><Field name="lender" label="Organisme de financement" required value={lender} maxLength={150} placeholder="Banque ou société de financement" onChange={e=>setLender(e.target.value)} error={errors.lender}/><Field name="reference" help="loan_reference" label="Numéro du contrat" required value={reference} maxLength={100} onChange={e=>setReference(e.target.value)} error={errors.reference}/></div><p className="lc-note">Suivi à partir du <strong>{date(tracking)}</strong>. {mode==='existing'?'À l’étape suivante, indiquez ce qui était déjà réglé avant cette date.':'Vous enregistrerez les paiements depuis le dossier.'}</p><details className="lc-options"><summary>Dates, copie du contrat et informations complémentaires</summary><div className="lc-grid"><Field name="tracking_from" label="Début du suivi dans le système" type="date" required max={today} value={tracking} onChange={e=>{setTracking(e.target.value);setOpeningOK(false);}} error={errors.tracking_from}/><Field name="signed_on" label="Date du contrat · facultatif" type="date" max={today} value={signed} onChange={e=>setSigned(e.target.value)} error={errors.signed_on}/>{!buying&&<Field name="financed_centimes" label="Montant financé initial · DH" inputMode="decimal" value={financed} onChange={e=>setFinanced(e.target.value)} error={errors.financed_centimes}/>}<Field name="file" help="loan_file" label="Contrat ou tableau de la banque" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={e=>setFile(e.target.files?.[0]??null)} error={errors.file}/></div>{file&&<p className="lc-note">Pièce sélectionnée : {file.name} <button type="button" className="text-button" onClick={()=>setFile(null)}>Retirer</button></p>}<p className="lc-note">Pièce privée · PDF, JPG, PNG ou WebP · 5 Mo maximum. Le montant financé est informatif ; les échéances déterminent le total à régler.</p></details></section></>}
 {step===1&&<>
 <section className="lc-section"><h3>Les mensualités du contrat</h3>
 {buying&&<><div className="lc-calculation-mode" role="group" aria-label="Préparation des mensualités"><button type="button" aria-pressed={automatic} onClick={()=>chooseSchedule('automatic')}>Calcul automatique</button><button type="button" aria-pressed={!automatic} onClick={()=>chooseSchedule('bank')}>Montants de la banque</button></div><p className="lc-note">Prix {money(safe(purchasePrice))} − apport {money(safe(downPayment))} = <strong>{money(safe(purchasePrice)-safe(downPayment))} à répartir</strong>.</p></>}
 <p className="lc-subtitle">{automatic?'Changez le nombre de mois ou la mensualité : l’autre valeur et le tableau se calculent automatiquement.':'Recopiez les mensualités prévues par votre organisme, intérêts et frais inclus s’ils figurent au contrat.'}</p>
 <div className="lc-grid lc-grid-three">
 <Field name="generator_count" label={automatic?'Nombre de mois':'Nombre total d’échéances'} type="number" min={1} max={360} value={effectiveCount} onChange={e=>changeGenerator('count',e.target.value)} error={errors.generator_count||(automatic&&calculationBasis==='count'?calculationError:undefined)}/>
 <Field name="generator_amount" label={automatic?'Mensualité · DH':'Montant habituel · DH'} value={effectiveAmount} inputMode="decimal" placeholder="3000" onChange={e=>changeGenerator('amount',e.target.value)} error={errors.generator_amount||(automatic&&calculationBasis==='amount'?calculationError:undefined)}/>
 <Field name="generator_first" label="Première échéance du contrat" type="date" value={first} onChange={e=>changeGenerator('first',e.target.value)} error={errors.generator_first}/>
 </div>
 {automatic&&plan&&<div className="lc-calculation-result" role="status"><strong>{plan.count===1?money(plan.last):plan.last===plan.regular?plan.count+' mois × '+money(plan.regular):(plan.count-1)+' mois × '+money(plan.regular)+' + dernier versement de '+money(plan.last)}</strong><span>Total : {money(financedAmount)} · fin prévue {validFirst?date(monthDate(first,plan.count-1)):'à préciser'}</span><small>Répartition du montant financé, sans intérêts ni frais ajoutés. Si votre contrat en prévoit, choisissez « Montants de la banque ».</small></div>}
 {mode==='existing'&&<div className="lc-opening lc-history-entry"><h3>Ce qui était déjà payé avant le {date(tracking)}</h3><div className="lc-grid">
 <Field name="past" label="Combien d’échéances entièrement payées ?" type="number" min={0} max={Number(count)||360} value={past} onChange={e=>{setPast(e.target.value);clearFieldError('past');}} error={errors.past}/>
 <Field name="loan_partial_opening" label="Déjà payé sur la suivante · DH" inputMode="decimal" value={partial} onChange={e=>{setPartial(e.target.value);clearFieldError('loan_partial_opening');}} error={errors.loan_partial_opening}/>
 </div><p>Exemple : 9 échéances entières et 1000 DH sur la 10e. Laissez 0 si elle n’a pas encore été payée.</p><small>Ces anciens règlements réduisent le solde. Ils ne créent aucun nouveau paiement.</small></div>}
 {customized&&<p className="lc-note">Vous avez ajusté certaines lignes. Refaire le tableau remplacera ces ajustements.</p>}
 {!automatic&&rows.length>0&&generatedFrom!==fingerprint&&<p className="lc-alert" role="status">Les paramètres ont changé. Actualisez le tableau pour les appliquer.</p>}
 {!automatic&&<button className="primary" data-loan-field="generator" onClick={generate}>{rows.length?'Actualiser le tableau':'Afficher le tableau'}</button>}{errors.generator&&<p className="lc-error" role="alert">{errors.generator}</p>}
 </section>
 {rows.length>0&&(automatic?<details className="lc-options lc-auto-table" id="loan-prepared-table"><summary>Voir les {rows.length} échéances · {money(total)}</summary><p className="lc-note">Modifier une ligne passe aux montants personnalisés de la banque.</p><LoanSchedule rows={rows} mode={mode} editing={editing} page={tablePage} errors={errors} onEdit={setEditing} onPage={setTablePage} onChange={changeRow} onDone={()=>{if(validate(1))setEditing(null);}}/></details>:<section className="lc-section" id="loan-prepared-table"><div className="lc-section-title"><div><span className="lc-eyebrow">VOTRE TABLEAU EST PRÊT</span><h3>{rows.length} échéances · {money(total-opening)} restant à régler</h3></div></div><p className="lc-note">Vérifiez les montants avec votre contrat. Modifiez seulement les lignes différentes.</p><LoanSchedule rows={rows} mode={mode} editing={editing} page={tablePage} errors={errors} onEdit={setEditing} onPage={setTablePage} onChange={changeRow} onDone={()=>{if(validate(1))setEditing(null);}}/></section>)}
 </>}
 {step===2&&<><section className="lc-review">
 <span className="lc-eyebrow">À VÉRIFIER AVANT ENREGISTREMENT</span>
 <h3>{vehicle?.make} {vehicle?.model} <span>{vehicle?.plate}</span></h3>
 <p>{lender} · Contrat {reference}</p>
 {buying?<><p className="lc-purchase-agreement">Prix d’achat : <strong>{money(safe(purchasePrice))}</strong> · Apport prévu : <strong>{money(safe(downPayment))}</strong> · Montant financé : <strong>{money(safe(purchasePrice)-safe(downPayment))}</strong></p>
 <PurchaseBalance apportRemaining={safe(downPayment)} installmentsRemaining={total-opening} installmentCount={rows.filter(r=>safe(r.amount)>safe(r.opening)).length}/>
 </>:<div className="lc-metrics"><div><span>Total des mensualités</span><strong>{money(total)}</strong></div><div><span>Mensualités déjà réglées</span><strong>{money(opening)}</strong><small>Avant le {date(tracking)}</small></div><div className="lc-emphasis"><span>Reste des mensualités</span><strong>{money(total-opening)}</strong></div></div>}
 <p>{rows.length} échéances · du {date(rows[0]?.due)} au {date(rows.at(-1)?.due)}</p>
 {automatic&&<p className="lc-note">Répartition du montant financé, sans intérêts ni frais ajoutés.</p>}
 {buying&&!automatic&&<p className="lc-note">Les mensualités reprennent votre tableau de financement, intérêts et frais inclus s’ils y figurent.</p>}
 <p className="lc-note"><strong>Aucun nouveau paiement enregistré à cette étape.</strong> {buying?'Après l’enregistrement, vous pourrez saisir le versement de l’apport et les mensualités depuis ce dossier.':'Les anciens règlements ne seront pas comptés une deuxième fois. Les prochains versements seront saisis depuis ce dossier.'}</p>
 </section><Check name="schedule_confirmed" checked={scheduleOK} onChange={setScheduleOK} error={errors.schedule_confirmed}>J’ai vérifié les dates et les montants avec le tableau de financement. Ce tableau sera conservé après enregistrement.</Check>{opening>0&&<Check name="opening_confirmed" checked={openingOK} onChange={setOpeningOK} error={errors.opening_confirmed}>Je confirme que {money(opening)} ont déjà été réglés avant le {date(tracking)}. Je ne les enregistrerai pas une deuxième fois.</Check>}</>}
 </fieldset></div><footer className="lc-dialog-footer"><button className="secondary" disabled={busy||uncertain} onClick={()=>step>0?setStep(step-1):close()}>{step>0?'Retour':'Annuler'}</button><span>{step===1&&rows.length>0?`${rows.length} échéances · Mensualités restantes ${money(total-opening)}`:'Étape '+(step+1)+' / 3'}</span>{step<2?<button className="primary" onClick={()=>{if(validate(step)){setStep(step+1);dialog.current?.querySelector('.lc-dialog-body')?.scrollTo(0,0);}}}>Continuer →</button>:<button className="primary" disabled={busy} onClick={save}>{busy?'Enregistrement…':uncertain?'Réessayer le même enregistrement':buying?'Enregistrer l’achat et le crédit':'Enregistrer le contrat'}</button>}</footer></dialog>,document.body);
}
