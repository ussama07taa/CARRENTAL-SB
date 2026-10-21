'use client';
import {useState} from 'react';
import {Check,TriangleAlert} from 'lucide-react';
import ReturnReadinessDialog,{ReturnBlocker} from './return-readiness-dialog';
import {localDate} from './request-types';

type Gate={key:string;label:string;ok:boolean;detail:string;target:string|null};
type Readiness={return_blocker?:ReturnBlocker|null;agreement_starts_local?:string;ready:boolean;can_record:boolean;checks:Gate[];server_now_local:string;starts_local:string;ends_local:string;vehicle_id:number};

export default function PickupReadiness({id,data,disabled,onNavigate,onResolved,simulation=false}:{id:number;data:Readiness;disabled:boolean;onNavigate?:(target:string)=>void;simulation?:boolean;onResolved?:()=>void}){
 const [returnOpen,setReturnOpen]=useState(false);
 const [datesOpen,setDatesOpen]=useState(false);
 const missing=data.checks.filter(check=>!check.ok);
 const late=data.can_record&&data.server_now_local>(data.agreement_starts_local??data.starts_local);
 const future=data.server_now_local<data.starts_local,ended=data.server_now_local>=data.ends_local;
 function explanation(check:Gate){
  if(check.key==='vehicle'&&data.return_blocker)return {title:`${data.return_blocker.vehicle_name} · remise bloquée`,body:`Cette voiture a un retour enregistré dans DEM-${data.return_blocker.reservation_id}, mais sa disponibilité après contrôle reste à valider. Ouvrez le retour de cette voiture ici pour vérifier ce qui manque.`};
  if(check.key==='contract'&&check.detail.includes('Le modèle actuel est un test'))return {title:'Le modèle de contrat est encore un TEST',body:'Signer et vérifier la copie ne suffit pas à autoriser un départ réel. Les mentions de l’agence et l’assurance restent à finaliser dans le modèle.'};
  if(check.key==='time')return future?{title:'Le créneau de location n’a pas encore commencé',body:`Départ prévu le ${localDate(data.starts_local)}. Attendez ce créneau. Si le client souhaite partir plus tôt, faites revoir l’accord, la période et les documents avant la remise.`}:ended?{title:'La période de location est terminée',body:`Le retour prévu était le ${localDate(data.ends_local)}. Faites revoir la période et l’accord du dossier avant un nouveau départ.`}:{title:'Le moment de remise est hors de la période convenue',body:check.detail+' Vérifiez le créneau et le moment saisi.'};
  return {title:check.label,body:check.detail};
 }
 const targetName=(key:string)=>({documents:'Compléter les documents',payment:'Revoir le paiement',payments:'Revoir le paiement',caution:'Compléter la caution',inspection:'Revoir l’inspection',contract:'Voir le contrat et ses informations'})[key]??'Voir le dossier';
 return <section id={'pickup-readiness-'+id} className={'du-readiness '+(data.can_record&&!late?'is-ready':'is-blocked')} aria-label="Situation avant remise des clés">
  <p className="du-context">Dossier DEM-{String(id).padStart(6,'0')}{simulation?' · ESPACE TEST SÉPARÉ · REMISE FICTIVE':''}</p>
  <header>{data.can_record&&!late?<Check size={22}/>:<TriangleAlert size={22}/>}<div><h4>{late?'Départ prévu dépassé — accord à confirmer':data.can_record?(simulation?'Prêt pour la remise fictive TEST':'Prêt pour la remise des clés'):'Pourquoi le départ est bloqué'}</h4><p>{late?"Vérifiez les dates et l’accord avec le client avant de confirmer la remise.":data.can_record?`Les ${data.checks.length} contrôles sont validés. ${simulation?'La simulation reste à confirmer.':'Le départ reste à confirmer après la remise effective des clés.'}`:!data.ready?'L’enregistrement du départ n’est pas encore disponible.':`${missing.length} point${missing.length>1?'s':''} à régler avant de pouvoir enregistrer le départ.`}</p></div></header>
  {!data.can_record&&<ol className="du-blockers">{missing.map(check=>{const message=explanation(check);return <li key={check.key}><div><strong>{message.title}</strong><p>{message.body}</p></div>{check.key==='time'?<button type="button" className="secondary" disabled={disabled} onClick={()=>setDatesOpen(value=>!value)} aria-expanded={datesOpen}>Voir les dates du dossier</button>:check.key==='vehicle'&&data.return_blocker?<button type="button" className="primary" disabled={disabled} onClick={()=>setReturnOpen(true)}>Revoir et valider la disponibilité</button>:check.target==='vehicle'?<a className="secondary" href={`/vehicles?vehicle=${data.vehicle_id}`} target="_blank" rel="noopener noreferrer">Voir la fiche voiture</a>:check.target&&onNavigate?<button type="button" className="secondary" disabled={disabled} onClick={()=>onNavigate(check.target!)}>{targetName(check.target)}</button>:null}</li>;})}</ol>}
  {data.return_blocker&&<p>Une voiture peut être réservable sur un créneau sans être encore prête pour une remise immédiate. Le retour précédent doit être contrôlé et sa disponibilité validée avant de remettre les clés.</p>}
  {returnOpen&&data.return_blocker&&<ReturnReadinessDialog currentId={id} blocker={data.return_blocker} onClose={()=>{setReturnOpen(false);onResolved?.();}}/>}
  <dl className="du-period"><div><dt>Départ convenu · heure du Maroc</dt><dd>{localDate(data.starts_local)}</dd></div><div><dt>Retour convenu</dt><dd>{localDate(data.ends_local)}</dd></div><div><dt>Heure du serveur utilisée pour le contrôle</dt><dd>{localDate(data.server_now_local)}</dd></div></dl>
  {datesOpen&&<p className="du-date-explanation" role="status">Le moment de remise doit être compris entre le départ et le retour convenus, et ne peut pas être dans le futur. Consulter ces dates ne les modifie pas.</p>}
  <p className="du-status-next">{data.can_record?(simulation?'Étape suivante : « Simuler la remise des clés », puis confirmer la remise fictive.':'Étape suivante : « Remettre les clés », puis confirmer la remise réelle.'):'Le bouton « Remettre les clés » reste désactivé tant que ces points ne sont pas réglés.'} Le dossier passe à « En location » uniquement après l’enregistrement du départ.</p>
  <details className="du-status-help"><summary>ⓘ Comprendre cette étape</summary><p lang="ar" dir="rtl">تأكيد الحجز والتوقيع بوحدهم ما كيعنيوش أن الطوموبيل خرجات. إلا كان شي منع، السبب وشنو خاص يتدار كيبانو هنا. العقد التجريبي ما كيسمحش بخروج حقيقي، وموعد التسليم خاص يكون داخل المدة المتفق عليها. ملي تكمل الشروط ويتسجل تسليم المفاتيح، عاد كيولي الملف «En location».</p></details>
 </section>;
}
