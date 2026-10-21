'use client';
import {FileText, ShieldCheck, ClipboardCheck, Receipt, Info, Paperclip, Route} from 'lucide-react';
import './vehicle-documents-clarity.css';
import DocumentViewer from './document-viewer';
import {validateFields,focusFieldError} from '../_lib/form-validation';
import FieldHelp from '../_components/field-help';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { api, apiMutation } from '../_lib/auth-client';
type Kind='registration'|'insurance'|'inspection'|'tax'|'circulation';
type Doc={id:number;kind:Kind;version:number;change_kind:string;reason:string|null;effective_on:string|null;expires_on:string|null;provider:string|null;policy_number:string|null;tax_year:number|null;paid_on:string|null;mime:string|null;size:number|null;has_file:boolean;created_at:string;actor?:string;deadline:{state:string;days:number|null}};
type Group={latest:Doc|null;current:Doc|null;history:Doc[];total:number};
type Groups=Record<Kind,Group>;
type Draft={effective_on:string;expires_on:string;provider:string;policy_number:string;tax_year:string;paid_on:string;reason:string};
const kinds: [Kind,string,string][]=[['registration','Carte grise','Identification du véhicule'],['insurance','Assurance','Contrat et fin de validité'],['inspection','Visite technique','Contrôle et prochain rendez-vous'],['tax','Vignette','Année payée et justificatif'],['circulation','Feuille de circulation','Validité et renouvellement']];
const documentIcons={registration:FileText,insurance:ShieldCheck,inspection:ClipboardCheck,tax:Receipt,circulation:Route};
const empty:Draft={effective_on:'',expires_on:'',provider:'',policy_number:'',tax_year:'',paid_on:'',reason:''};
const formatDate=(s:string)=>new Intl.DateTimeFormat('fr-MA',{dateStyle:'medium'}).format(new Date(s+'T12:00:00'));
function status(d:Doc|null){
 if(!d)return {label:'Information manquante',tone:'empty'};
 const days=d.deadline.days;
 switch(d.deadline.state){
 case 'overdue':return {label:'Échéance dépassée',tone:'danger'};
 case 'urgent':return {label:days===0?'Échéance aujourd’hui':'Dans '+days+' jour'+(days===1?'':'s'),tone:'danger'};
 case 'soon':return {label:'Dans '+days+' jours',tone:'warning'};
 case 'scheduled':return {label:'À venir',tone:'scheduled'};
 default:return {label:d.kind==='tax'?'Payée pour '+d.tax_year:d.kind==='registration'?'Enregistrée':'À jour',tone:'ok'};
 }
}
function plainSummary(kind:Kind, group:Group){
 const d=group.latest;
 if(!d)return {title:'Pas encore renseignée',detail:kind==='inspection'?'Aucun contrôle enregistré. Une voiture neuve peut ne pas en avoir encore passé.':'Ajoutez les informations quand vous avez le document.'};
 if(kind==='circulation')return {title:(d.deadline.state==='overdue'?'Feuille expirée le ':'Feuille valable jusqu’au ')+(d.expires_on?formatDate(d.expires_on):'date à renseigner'),detail:d.deadline.state==='scheduled'?'Renouvellement futur : vérifier la version actuellement valable.':'Dates recopiées du document. Rappel à 30 jours, urgent à 7 jours.'};
 if(kind==='registration')return {title:'Carte grise enregistrée',detail:d.has_file?'Le document est joint au dossier.':'Le justificatif reste à joindre.'};
 if(kind==='tax')return {title:'Vignette payée pour '+d.tax_year,detail:'Paiement enregistré le '+(d.paid_on?formatDate(d.paid_on):'—')+'.'};
 if(kind==='inspection')return {title:'Prochain contrôle : '+(d.expires_on?formatDate(d.expires_on):'date à renseigner'),detail:'Dernier contrôle enregistré : '+(d.effective_on?formatDate(d.effective_on):'—')+'.'};
 if(d.deadline.state==='scheduled')return {title:'Assurance prévue dès le '+formatDate(d.effective_on!),detail:'Cette nouvelle période n’a pas encore commencé.'};
 return {title:(d.deadline.state==='overdue'?'Assurance expirée le ':'Assurance jusqu’au ')+(d.expires_on?formatDate(d.expires_on):'—'),detail:d.provider||'Compagnie non renseignée.'};
}
type DocumentHealth={blocked:boolean;attention:boolean;label:string;detail:string;tone:string;action:string};
export type DocumentOverview={vehicleId:number;blocked:number;attention:number;labels:string[]};
function documentHealth(kind:Kind,group:Group,today:string):DocumentHealth{
 const latest=group.latest,required=['registration','insurance','inspection','circulation'].includes(kind);
 const scheduled=!!latest?.effective_on&&latest.effective_on>today&&kind!=='registration'&&kind!=='tax';
 const current=scheduled?group.current:latest;
 if(!latest)return {blocked:required,attention:true,label:required?'Manquant · bloque la remise':'À compléter',detail:required?'Aucune pièce enregistrée. Complétez ce document avant de remettre les clés.':'Aucune information enregistrée pour ce document.',tone:required?'blocked':'attention',action:'Compléter ce document'};
 if(kind==='registration'&&!latest.has_file)return {blocked:true,attention:true,label:'Fichier manquant · bloque la remise',detail:'Les informations existent, mais la copie de la carte grise manque.',tone:'blocked',action:'Joindre la carte grise'};
 if(required&&(!current||!current.expires_on)&&kind!=='registration')return {blocked:true,attention:true,label:scheduled?'Pas encore valable · bloque la remise':'Validité à compléter · bloque la remise',detail:scheduled?'La nouvelle période n’a pas commencé et aucune couverture actuelle valable n’est enregistrée.':'La date de fin de validité manque.',tone:'blocked',action:'Revoir les informations'};
 if(scheduled&&!current&&!required)return {blocked:false,attention:true,label:'Validité future · à vérifier',detail:'La période enregistrée n’a pas encore commencé. Vérifiez la pièce actuellement valable.',tone:'attention',action:'Revoir les informations'};
 if(kind==='circulation'&&current&&(!current.has_file||!current.effective_on))return {blocked:true,attention:true,label:'Pièce incomplète · bloque la remise',detail:!current.has_file?'La copie de la feuille de circulation manque. Joignez le document valable.':'Le début de validité manque.',tone:'blocked',action:!current.has_file&&!scheduled?'Joindre le justificatif':'Revoir les informations'};
 const expired=!!current?.expires_on&&current.expires_on<today;
 if(expired)return {blocked:required||kind==='tax',attention:true,label:kind==='tax'?'Rappel dépassé · bloque la remise':required?'Expiré · bloque la remise':'Expiré · à renouveler',detail:(kind==='tax'?'Échéance de suivi enregistrée dépassée le ':'Validité enregistrée terminée le ')+formatDate(current!.expires_on!)+'.',tone:required||kind==='tax'?'blocked':'attention',action:scheduled?'Revoir les informations':'Renouveler ce document'};
 if(!latest.has_file)return {blocked:false,attention:true,label:'Copie à joindre',detail:'Les informations sont enregistrées. Le justificatif reste à joindre.',tone:'attention',action:'Joindre le justificatif'};
 if(current&&['urgent','soon'].includes(current.deadline.state))return {blocked:false,attention:true,label:'Échéance proche',detail:'Valable aujourd’hui. Préparez le renouvellement avant le '+formatDate(current.expires_on!)+'.',tone:'attention',action:'Préparer le renouvellement'};
 return {blocked:false,attention:false,label:scheduled?'Valable · renouvellement prévu':'Document renseigné',detail:scheduled?'La version actuelle couvre aujourd’hui. La prochaine période est conservée.':'Aucun manque détecté pour ce document aujourd’hui.',tone:'complete',action:''};
}
export default function VehicleDocuments({vehicleId,onSaved,onOverview}:{vehicleId:number;onSaved?:()=>void;onOverview?:(value:DocumentOverview|null)=>void}){
 const [groups,setGroups]=useState<Groups|null>(null),[loading,setLoading]=useState(true),[loadError,setLoadError]=useState(''),[notice,setNotice]=useState('');
 const [extraHistory,setExtraHistory]=useState<Partial<Record<Kind,Doc[]>>>({}),[loadingHistory,setLoadingHistory]=useState<Kind|null>(null);
 useEffect(()=>{if(new URLSearchParams(window.location.search).get('tab')==='documents'){const target=document.getElementById('v-doc-title');target?.scrollIntoView({block:'start'});target?.focus({preventScroll:true});}},[vehicleId]);
 const [today,setToday]=useState(''),[history,setHistory]=useState<Kind|null>(null);
 const [editor,setEditor]=useState<{kind:Kind;previous:Doc|null;operation:'initial'|'renewal'|'correction';attachOnly:boolean}|null>(null);
 const [draft,setDraft]=useState<Draft>({...empty}),[file,setFile]=useState<File|null>(null),[retain,setRetain]=useState(false);
 const [error,setError]=useState(''),[busy,setBusy]=useState(false),[downloading,setDownloading]=useState<number|null>(null);
 const dialog=useRef<HTMLDialogElement>(null),form=useRef<HTMLFormElement>(null),input=useRef<HTMLInputElement>(null),key=useRef(''),lock=useRef(false);
 const [preview,setPreview]=useState('');
 const [viewing,setViewing]=useState<Doc|null>(null);
 const baseline=useRef(''),requestSequence=useRef(0);
 const dirty=!!editor&&(JSON.stringify(draft)!==baseline.current||file!==null);
 async function load(signal?:AbortSignal){
 const seq=++requestSequence.current;
 try{
 const r=await fetch(api+'/api/v1/vehicles/'+vehicleId+'/documents',{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:signal?AbortSignal.any([signal,AbortSignal.timeout(10000)]):AbortSignal.timeout(10000)});
 if(r.status===401){window.location.replace('/login');return;}
 if(!r.ok)throw new Error('Les documents n’ont pas pu être chargés.');
 const body=await r.json();if(seq!==requestSequence.current||signal?.aborted)return;
 setGroups(body.data);setToday(body.today);setLoadError('');
 }catch(e){if(!signal?.aborted&&seq===requestSequence.current)setLoadError(e instanceof Error?e.message:'Connexion impossible.');}
 finally{if(!signal?.aborted&&seq===requestSequence.current)setLoading(false);}
 }
 useEffect(()=>{const abort=new AbortController();void load(abort.signal);
 const refresh=()=>{if(document.visibilityState==='visible')void load(abort.signal);};
 document.addEventListener('visibilitychange',refresh);const timer=setInterval(refresh,60000);
 return()=>{abort.abort();clearInterval(timer);document.removeEventListener('visibilitychange',refresh);requestSequence.current++;};},[vehicleId]);
 useEffect(()=>{if(editor)dialog.current?.showModal();},[editor]);
 useEffect(()=>{if(!file||!file.type.startsWith('image/')){setPreview('');return;}const url=URL.createObjectURL(file);setPreview(url);return()=>URL.revokeObjectURL(url);},[file]);
 useEffect(()=>{const warn=(e:BeforeUnloadEvent)=>{if(dirty||busy){e.preventDefault();e.returnValue='';}};window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[dirty,busy]);
 const [fieldErrors,setFieldErrors]=useState<Record<string,string[]>>({});
 function reportErrors(errors:Record<string,string[]>){setFieldErrors(errors);setError('Vérifiez les champs signalés ci-dessous.');setTimeout(()=>focusFieldError(form.current,errors),0);}
 function open(kind:Kind,operation:'initial'|'renewal'|'correction',attachOnly=false){
 const previous=groups?.[kind]?.latest??null;
 const next:Draft=operation==='correction'&&previous?{effective_on:previous.effective_on??'',expires_on:previous.expires_on??'',provider:previous.provider??'',policy_number:previous.policy_number??'',tax_year:String(previous.tax_year??''),paid_on:previous.paid_on??'',reason:''}:{...empty,provider:previous?.provider??'',policy_number:previous?.policy_number??''};
 if(attachOnly)next.reason='Ajout du justificatif sans modification des informations';
 setFieldErrors({});setDraft(next);baseline.current=JSON.stringify(next);setFile(null);setRetain(operation==='correction'&&!!previous?.has_file);setError('');key.current=crypto.randomUUID();setEditor({kind,previous,operation,attachOnly});
 }
 function close(){if(lock.current)return;if(dirty&&!window.confirm('Quitter sans enregistrer cette pièce ?'))return;setEditor(null);}
 function choose(file?:File){
 if(!file)return;
 if(!['application/pdf','image/jpeg','image/png','image/webp'].includes(file.type)){reportErrors({file:['Choisissez un PDF ou une image JPG, PNG ou WebP.']});return;}
 if(file.size>5*1024*1024){reportErrors({file:['Le fichier dépasse 5 Mo.']});return;}
 setFieldErrors(v=>({...v,file:[]}));setFile(file);setRetain(false);setError('');key.current=crypto.randomUUID();
 }
 function update(field:keyof Draft,value:string){setFieldErrors(v=>({...v,[field]:[]}));setDraft(v=>({...v,[field]:value}));}
 async function save(e:FormEvent){
 e.preventDefault();e.stopPropagation();if(!editor||lock.current)return;
 if(!form.current)return;const invalid=validateFields(form.current);if(Object.keys(invalid).length){reportErrors(invalid);return;}
 if(editor.attachOnly&&!file){reportErrors({file:['Choisissez le justificatif à joindre.']});return;}
 lock.current=true;setBusy(true);setError('');
 try{
 const data=new FormData();data.append('kind',editor.kind);data.append('change_kind',editor.operation);data.append('previous_id',editor.previous?String(editor.previous.id):'');data.append('request_key',key.current);data.append('retain_file',retain&&!file?'1':'0');
 for(const [name,value] of Object.entries(draft))if(value)data.append(name,value);
 if(file)data.append('file',file);
 const r=await apiMutation('vehicles/'+vehicleId+'/documents','POST',data);
 if(r.status===401){window.location.replace('/login');return;}
 const body=await r.json().catch(()=>({}));
 if(!r.ok){if(body.errors){reportErrors(body.errors);return;}throw new Error(body.message??'Enregistrement impossible. Réessayez.');}
 setEditor(null);setNotice('Pièce enregistrée. Les versions précédentes restent conservées.');await load();onSaved?.();
 }catch(e){setError(e instanceof Error?e.message:'Enregistrement impossible. Réessayez.');}
 finally{lock.current=false;setBusy(false);}
 }
 async function download(d:Doc){
 if(downloading!==null)return;setDownloading(d.id);setLoadError('');
 try{
 const r=await fetch(api+'/api/v1/vehicles/'+vehicleId+'/documents/'+d.id+'/content',{credentials:'include',cache:'no-store',headers:{Accept:'application/octet-stream'},signal:AbortSignal.timeout(30000)});
 if(!r.ok)throw new Error('Le fichier n’a pas pu être téléchargé. Réessayez.');
 const blob=await r.blob();const url=URL.createObjectURL(blob);const link=document.createElement('a');
 const ext=d.mime==='application/pdf'?'pdf':d.mime==='image/png'?'png':d.mime==='image/webp'?'webp':'jpg';
 link.href=url;link.download=d.kind+'-v'+d.version+'.'+ext;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
 }catch(e){setLoadError(e instanceof Error?e.message:'Téléchargement impossible.');}finally{setDownloading(null);}
 }
 async function older(kind:Kind,before:number){
 if(loadingHistory)return;setLoadingHistory(kind);
 try{
 const r=await fetch(api+'/api/v1/vehicles/'+vehicleId+'/documents/history?'+new URLSearchParams({kind,before_version:String(before)}),{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.timeout(10000)});
 if(!r.ok)throw new Error('Impossible de charger les anciennes versions.');
 const body=await r.json();setExtraHistory(v=>({...v,[kind]:[...(v[kind]??[]),...body.data]}));
 }catch(e){setLoadError(e instanceof Error?e.message:'Historique indisponible.');}finally{setLoadingHistory(null);}
 }
 function field(name:keyof Draft,label:string,type='text',required=false,extra:Record<string,string|number>={}){
 const question:Record<string,string>={provider:'Quelle compagnie assure la voiture ?',policy_number:'Quel est le numéro du contrat ?',tax_year:'Pour quelle année avez-vous payé ?',paid_on:'À quelle date avez-vous payé ?',effective_on:editor?.kind==='circulation'?'Quand commence la validité ?':editor?.kind==='inspection'?'Quand le contrôle a-t-il été effectué ?':'Quand commence la couverture ?',expires_on:editor?.kind==='circulation'?'Quand expire la feuille de circulation ?':editor?.kind==='tax'?'Quand souhaitez-vous un rappel ?':editor?.kind==='inspection'?'Quand est prévu le prochain contrôle ?':'Quand se termine la couverture ?'};
 const hints:Record<string,string>={provider:'Le nom indiqué sur le contrat.',policy_number:'Recopiez le numéro du contrat, pas une date.',tax_year:'Exemple : 2026. Utilisez l’année indiquée sur le reçu.',paid_on:'La date du paiement réel, indiquée sur le reçu.',effective_on:editor?.kind==='circulation'?'Recopiez le début de validité indiqué sur la feuille.':editor?.kind==='inspection'?'La date du contrôle déjà réalisé, pas la date d’achat.':'La date de début indiquée sur le contrat.',expires_on:editor?.kind==='circulation'?'Quand expire la feuille de circulation ?':editor?.kind==='tax'?'Facultatif. Laissez vide si vous ne connaissez pas la date. Ce rappel ne fixe pas une échéance légale.':editor?.kind==='inspection'?'Recopiez le prochain rendez-vous indiqué sur le document.':'La date de fin indiquée sur le contrat.'};
 return <label className="v-field"><span>{question[name]??label}{required?' *':' (facultatif)'}<FieldHelp field={editor?.kind==='circulation'?'circulation':name} label={label}/></span><input aria-label={question[name]??label} name={name} aria-invalid={!!fieldErrors[name]?.length} aria-describedby={'doc-hint-'+name+(fieldErrors[name]?.length?' doc-error-'+name:'')} type={type} required={required} value={draft[name]} onChange={e=>update(name,e.target.value)} {...extra}/><small className="vd-input-hint" id={'doc-hint-'+name}>{hints[name]}</small>{fieldErrors[name]?.[0]&&<small id={'doc-error-'+name} className="v-error">{fieldErrors[name][0]}</small>}</label>;
 }
 const health=groups?kinds.map(([kind,label])=>({kind,name:label,...documentHealth(kind,groups[kind]??{latest:null,current:null,history:[],total:0},today)})):[];
 const blockedCount=health.filter(h=>h.blocked).length,attentionCount=health.filter(h=>h.attention).length;
 useEffect(()=>{onOverview?.(groups&&!loadError?{vehicleId,blocked:blockedCount,attention:attentionCount,labels:health.filter(h=>h.blocked).map(h=>h.name)}:null);},[groups,today,loadError,vehicleId,onOverview]);

 return <section className="v-documents vd-clear" aria-labelledby="v-doc-title">
 <div className="v-doc-heading"><div><span className="v-summary-eyebrow">SUIVI ADMINISTRATIF</span><h3 id="v-doc-title" tabIndex={-1}>Documents & échéances</h3><p>Les éléments à compléter sont visibles ci-dessous. Contrôle des documents à la date du jour ; les autres conditions de départ restent vérifiées dans la réservation.</p></div>{attentionCount>0&&<span className="v-doc-attention">{attentionCount} à traiter</span>}</div>
 {notice&&<p className="v-doc-notice" role="status">{notice}</p>}
 {loadError&&<div className="v-error-box" role="alert">{loadError} <button type="button" className="text-button" onClick={()=>void load()}>Réessayer</button></div>}
 {loading?<p role="status">Chargement des documents…</p>:groups&&<div className="v-doc-list">{kinds.map(([kind,label,description])=>{
 const group=groups[kind]??{latest:null,current:null,history:[],total:0},d=group.latest,s=status(d),DocumentIcon=documentIcons[kind],summary=plainSummary(kind,group),health=documentHealth(kind,group,today);
 const records=Array.from(new Map([...group.history,...(extraHistory[kind]??[])].map(item=>[item.id,item])).values()).sort((a,b)=>b.version-a.version);
 return <div className={'v-doc-row vd-'+s.tone+' vd-health-'+health.tone} key={kind}>
 <div className="vd-card-heading"><span className="v-doc-icon" aria-hidden="true"><DocumentIcon size={22}/></span><h4>{label}</h4></div>
 <span className={'vd-health-badge '+health.tone}>{health.label}</span><p className="vd-card-title">{!d?'Document à compléter':summary.title}</p><p className="vd-card-description">{health.detail}</p>
 {health.action&&<button type="button" className="vd-health-action" aria-label={health.action+' · '+label} onClick={()=>open(kind,!d?'initial':health.action.includes('Joindre')?'correction':health.action==='Revoir les informations'?'correction':'renewal',!!d&&health.action.includes('Joindre'))}>{health.action} →</button>}
 {d&&['overdue','urgent','soon'].includes(d.deadline.state)&&<p className={'vd-card-alert '+s.tone}>{kind==='circulation'?'Fin de validité : ':kind==='tax'?'Rappel de paiement : ':kind==='inspection'?'Prochain contrôle : ':'Fin d’assurance : '}{s.label}{d.expires_on?' · '+formatDate(d.expires_on):''}</p>}
 {group.current&&<p className={"vd-card-alert "+(group.current.expires_on&&group.current.expires_on<today?"danger":"")}>Validité précédente : {status(group.current).label} · fin le {formatDate(group.current.expires_on!)}.</p>}
 <details className="vd-card-details"><summary><span>Voir les détails</span><span aria-hidden="true">＋</span></summary><div className="vd-expanded">
 <p className={'vd-file '+(d?.has_file?'attached':'missing')}><Paperclip size={15}/>{d?.has_file?'Justificatif joint':d?'Justificatif à joindre':'Aucun document enregistré'}</p>
 <div className="vd-recorded" data-label="Informations enregistrées">{!d?<><strong>À renseigner</strong><p>Les informations de cette pièce ne sont pas encore enregistrées.</p></>:<>
 {kind==='registration'?<><strong>Carte grise enregistrée</strong><p>Document d’identification du véhicule.</p></>:kind==='tax'?<dl><div><dt>Année payée</dt><dd>{d.tax_year}</dd></div><div><dt>Paiement enregistré le</dt><dd>{d.paid_on?formatDate(d.paid_on):'Non renseigné'}</dd></div></dl>:kind==='insurance'?<dl><div><dt>Assureur</dt><dd>{d.provider||'Non renseigné'}</dd></div>{d.policy_number&&<div><dt>N° de contrat</dt><dd>{d.policy_number}</dd></div>}<div><dt>Début de couverture</dt><dd>{d.effective_on?formatDate(d.effective_on):'Non renseigné'}</dd></div></dl>:<dl><div><dt>Contrôle enregistré le</dt><dd>{d.effective_on?formatDate(d.effective_on):'Non renseigné'}</dd></div></dl>}
 <small className="vd-version">Version {d.version} · historique conservé</small></>}</div>
 <div className="v-doc-date" data-label="Prochain suivi">{kind==='registration'?<><strong>Pas de date d’expiration suivie</strong><p>À mettre à jour si les informations changent.</p></>:!d?<><span className="v-deadline empty">À vérifier</span><p>Aucune date enregistrée. Ce n’est pas une alerte de retard.</p></>:<>
 <span className="vd-date-caption">{kind==='circulation'?'Fin de validité':kind==='tax'?'Prochain paiement prévu':kind==='inspection'?'Prochain contrôle prévu':'Fin de couverture'}</span><strong className="vd-date-value">{d.expires_on?formatDate(d.expires_on):'Date non renseignée'}</strong>
 {d.expires_on&&<span className={'v-deadline '+s.tone}>{kind==='tax'&&s.tone==='ok'?'Rappel enregistré':s.label}</span>}
 {kind==='tax'&&<p>Rappel saisi par l’agence, non calculé automatiquement.</p>}
 {d.deadline.state==='scheduled'&&<p>Cette période n’a pas encore commencé. Vérifiez la couverture actuelle.</p>}
 {group.current&&<p className="v-doc-prior">Validité précédente : fin le {formatDate(group.current.expires_on!)} · {status(group.current).label}</p>}
 </>}</div>
 <div className="v-doc-actions" data-label="Actions">{d?.has_file&&<button type="button" className="v-doc-action" onClick={()=>setViewing(d)} aria-label={"Voir le document "+label}>Voir le document</button>}{d?.has_file&&<button type="button" className="text-button" disabled={downloading!==null} onClick={()=>void download(d)} aria-label={'Télécharger '+label}>{downloading===d.id?'Téléchargement…':'Télécharger'}</button>}
 <button type="button" className="v-doc-action" onClick={()=>open(kind,!d?'initial':kind==='registration'?'correction':'renewal')} aria-label={(!d?'Ajouter ':kind==='registration'?'Mettre à jour ':'Renouveler ')+label}>{!d?({registration:'Ajouter la carte grise',insurance:'Ajouter l’assurance',inspection:'Ajouter la visite technique',tax:'Ajouter la vignette',circulation:'Ajouter la feuille de circulation'}[kind]):kind==='registration'?'Mettre à jour':'Renouveler'} <span aria-hidden="true">↗</span></button>
 {d&&!d.has_file&&<button type="button" className="v-doc-action" onClick={()=>open(kind,'correction',true)} aria-label={'Joindre le document '+label}>Joindre le document</button>}
 {d&&kind!=='registration'&&<button type="button" className="text-button" onClick={()=>open(kind,'correction')} aria-label={'Corriger '+label}>Corriger les informations</button>}
 {d&&<button type="button" className="text-button" onClick={()=>setHistory(history===kind?null:kind)} aria-expanded={history===kind} aria-label={'Historique '+label}>Historique ({group.total})</button>}</div>
 {history===kind&&<div className="v-doc-history"><p>{records.length} version{records.length>1?'s':''} affichée{records.length>1?'s':''} sur {group.total}</p>{records.map(item=><div key={item.id}><div><strong>Version {item.version} · {item.change_kind==='initial'?'Ajout':item.change_kind==='renewal'?'Renouvellement':'Correction'}</strong><p>{item.expires_on?'Échéance : '+formatDate(item.expires_on):item.tax_year?'Année '+item.tax_year:'Carte grise'} · {item.actor??'Agence'}</p><p>Enregistrée le {new Intl.DateTimeFormat('fr-MA',{dateStyle:'medium',timeStyle:'short',timeZone:'Africa/Casablanca'}).format(new Date(item.created_at))}</p>{item.provider&&<p>{item.provider} · {item.policy_number||'Numéro non renseigné'}</p>}{item.reason&&<p>{item.reason}</p>}</div>{item.has_file?<><button type="button" className="v-doc-action" onClick={()=>setViewing(item)} aria-label={"Voir "+label+" version "+item.version}>Voir le document</button><button type="button" className="text-button" disabled={downloading!==null} onClick={()=>void download(item)} aria-label={'Télécharger '+label+' version '+item.version}>Télécharger</button></>:<small>Sans justificatif</small>}</div>)}{records.length<group.total&&<button type="button" className="secondary" disabled={loadingHistory!==null} onClick={()=>void older(kind,records[records.length-1].version)}>{loadingHistory===kind?'Chargement…':'Voir les versions précédentes'}</button>}</div>}
 </div></details></div>;})}</div>}
 {viewing&&<DocumentViewer vehicleId={vehicleId} document={viewing} onClose={()=>setViewing(null)}/>}
 {editor&&createPortal(<dialog ref={dialog} className="v-document-dialog vd-simple-editor" aria-labelledby="v-document-title" onCancel={e=>{e.preventDefault();e.stopPropagation();close();}} onClose={e=>e.stopPropagation()}>
 <form ref={form} onSubmit={save} noValidate>
 <header className="vehicle-header"><div><span className="fleet-eyebrow">{editor.attachOnly?'JOINDRE LE JUSTIFICATIF':editor.operation==='initial'?'AJOUTER UNE PIÈCE':editor.operation==='renewal'?'NOUVELLE PÉRIODE':'METTRE À JOUR'}</span><h2 id="v-document-title">{kinds.find(([kind])=>kind===editor.kind)?.[1]}</h2><p>{editor.previous?'La version précédente reste dans l’historique.':'Renseignez la pièce et son échéance.'}</p></div><button type="button" className="vehicle-close" onClick={close} disabled={busy} aria-label="Fermer le document">×</button></header>
 <div className="v-document-body">{error&&<div className="v-error-box" role="alert">{error}</div>}<fieldset disabled={busy} className="v-fieldset">
 {!editor.attachOnly&&<div className="vd-form-guide"><Info size={18}/><p>{editor.kind==='circulation'?'Recopiez les dates de validité de la feuille de circulation. Aucun délai légal n’est inventé. Les alertes apparaissent dans le tableau de bord avant expiration.':editor.kind==='tax'?'Recopiez l’année et la date figurant sur le reçu de paiement. Le prochain paiement est un rappel facultatif : laissez-le vide si la date n’est pas connue.':editor.kind==='inspection'?'Ce formulaire enregistre un contrôle déjà effectué. Pour une voiture neuve qui n’a pas encore passé de contrôle, ne saisissez pas une date fictive.':editor.kind==='insurance'?'Recopiez le début et la fin de couverture indiqués sur le contrat d’assurance. La date d’achat du véhicule peut être différente.':'Joignez la carte grise de cette voiture. Les anciennes versions restent disponibles dans l’historique.'}</p></div>}
 <div className="v-grid" hidden={editor.attachOnly}>
 {editor.kind==='insurance'&&<>{field('provider','Compagnie d’assurance','text',true,{maxLength:120})}{field('policy_number','Numéro de contrat','text',false,{maxLength:100})}{field('effective_on','Début de couverture','date',true)}{field('expires_on','Fin de couverture','date',true,{min:draft.effective_on})}</>}
 {editor.kind==='circulation'&&<>{field('effective_on','Début de validité','date',true)}{field('expires_on','Fin de validité','date',true,{min:draft.effective_on})}</>}
 {editor.kind==='inspection'&&<>{field('effective_on','Date du contrôle','date',true,{max:today})}{field('expires_on','Prochain contrôle','date',true,{min:draft.effective_on})}</>}
 {editor.kind==='tax'&&<>{field('tax_year','Année payée','number',true,{min:1950,max:Number(today.slice(0,4))+1})}{field('paid_on','Date de paiement','date',true,{max:today})}{field('expires_on','Prochain paiement prévu','date',false)}<p className="v-doc-form-help">Date saisie par l’agence. Aucun délai légal n’est calculé automatiquement.</p></>}
 {editor.operation==='correction'&&!editor.attachOnly&&<label className="v-field v-full"><span>Motif de la mise à jour *<FieldHelp field={"reason"} label={"Motif de la mise à jour"}/></span><textarea aria-label={"Motif de la mise à jour"} name="reason" aria-invalid={!!fieldErrors.reason?.length} aria-describedby={fieldErrors.reason?.length?"doc-error-reason":undefined} value={draft.reason} required maxLength={500} placeholder="Ex. correction de date, ajout du justificatif…" onChange={e=>update('reason',e.target.value)}/>{fieldErrors.reason?.[0]&&<small id="doc-error-reason" className="v-error">{fieldErrors.reason[0]}</small>}</label>}
 </div>
 <div className={"v-doc-upload "+(fieldErrors.file?.length?"field-upload-invalid":"")} onDragOver={e=>e.preventDefault()} onDrop={e=>{e.preventDefault();if(!busy){if(e.dataTransfer.files.length>1)setError('Choisissez un seul fichier. Regroupez les pages dans un PDF si nécessaire.');else choose(e.dataTransfer.files[0]);}}}>
 <input ref={input} name="file" aria-invalid={!!fieldErrors.file?.length} aria-describedby={fieldErrors.file?.length?"doc-error-file":undefined} type="file" className="sr-only" accept="application/pdf,image/jpeg,image/png,image/webp" aria-label="Fichier justificatif" onChange={e=>{choose(e.target.files?.[0]);e.target.value='';}}/>
 {preview&&<img src={preview} alt="Aperçu du justificatif choisi"/>}
 <strong>{file?file.name:retain?'Le fichier actuel sera conservé':'Joindre le justificatif'}</strong>
 {fieldErrors.file?.[0]&&<small id="doc-error-file" className="v-error">{fieldErrors.file[0]}</small>}<span>PDF, JPG, PNG ou WebP · 5 Mo maximum<FieldHelp field="file" label="Fichier justificatif"/></span>
 <button type="button" className="secondary" onClick={()=>input.current?.click()}>{file||retain?'Choisir un autre fichier':'Choisir un fichier'}</button>
 {file&&<button type="button" className="text-button" onClick={()=>{setFile(null);setRetain(editor.operation==='correction'&&!!editor.previous?.has_file);}}>Annuler le choix</button>}
 </div>
 {editor.attachOnly&&<p className="v-doc-form-help">Les dates et les informations restent inchangées. Cet ajout sera conservé dans l’historique.</p>}
 {editor.kind!=='registration'&&!editor.attachOnly&&<p className="v-doc-form-help">Vous pouvez enregistrer les dates sans fichier, puis joindre le justificatif plus tard.</p>}
 </fieldset></div>
 <footer className="vehicle-footer"><span className="v-doc-form-help">Document privé · espace agence</span><div><button type="button" className="secondary" disabled={busy} onClick={close}>Annuler</button><button type="submit" className="v-save" disabled={busy}>{busy?'Enregistrement…':editor.attachOnly?'Enregistrer le document':'Enregistrer la pièce'}</button></div></footer>
 </form>
 </dialog>,document.body)}
 </section>;
}
