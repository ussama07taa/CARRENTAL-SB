'use client';
import ExcelExchange from '../_components/excel-exchange';
import {Plus, Eye, Pencil, Camera, Wrench, CalendarDays, CarFront, RefreshCw} from 'lucide-react';
import './fleet-reference.css';
import {moroccoDateTimeFormat} from '../_lib/morocco-time';

import {useCreateShortcut} from '../_lib/use-create-shortcut';
import {validateFields,focusFieldError} from '../_lib/form-validation';
import FieldHelp from '../_components/field-help';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { api, apiMutation } from '../_lib/auth-client';
import VehiclePhotoGallery from './vehicle-photo-gallery';
import VehicleCreateDocuments, {creationDocumentKinds, creationDocumentPayload, documentField, validateCreationDocuments, type CreationDocuments} from './vehicle-create-documents';
import SeatsPicker from './seats-picker';
import { vehicleTypes } from './vehicle-types';
import VehicleSummary from './vehicle-summary';
import {parseDailyPrice} from './vehicle-pricing';
import ColorPicker from './color-picker';
import MakeModelPicker from './make-model-picker';
import { type PendingPhoto, type CustomerDocument } from '../clients/document-upload';

const equipmentOptions = [
 ['air_conditioning','Climatisation'],['bluetooth','Bluetooth'],['carplay','Apple CarPlay'],['android_auto','Android Auto'],
 ['usb','Prise USB'],['gps','GPS intégré'],['parking_sensors','Radar de recul'],['rear_camera','Caméra de recul'],
 ['cruise_control','Régulateur de vitesse'],['child_seat_mounts','Fixations ISOFIX'],['sunroof','Toit ouvrant'],
] as const;
const photoSlots = [['cover','Photo principale'],['front','Vue avant'],['rear','Vue arrière'],['interior','Habitacle'],['boot','Coffre'],['detail_1','Détail 1'],['detail_2','Détail 2'],['detail_3','Détail 3']] as const;
export type Slot = typeof photoSlots[number][0];
export type Photo = Omit<CustomerDocument,'slot'> & {slot: Slot};
type Localized = {fr:string;ar:string;en:string;es:string};
const blankText=():Localized=>({fr:"",ar:"",en:"",es:""});
type Fields = {public_description:Localized;custom_equipment:Localized[];published:boolean;vehicle_type:string;plate:string;make:string;model:string;trim:string;year:string;fuel:string;transmission:string;color:string;seats:string;doors:string;vin:string;equipment:string[]};
export type Vehicle = Omit<Fields,'year'|'seats'|'doors'> & {id:number;reference:string;version:number;year:number|null;seats:number|null;doors:number|null;current_odometer:number|null;current_odometer_date:string|null;cover_id?:number|null;normal_daily_centimes?:number|null};
export type Reading={id:number;kilometers:number;recorded_on:string;kind:string;reason:string|null;actor:string};
type Details={data:Vehicle;photos:Photo[];readings:Reading[];can_correct_odometer:boolean};
type Listing={can_manage_pricing:boolean;pricing_ready:boolean;data:Vehicle[];total:number;fleet_total:number;page:number;last_page:number;makes:string[]};
type Tab='info'|'equipment'|'photos'|'odometer'|'documents';
const baseTabs: [Tab,string][]=[['info','Informations'],['equipment','Équipements'],['photos','Photos'],['odometer','Compteur']];
const empty:Fields={public_description:blankText(),custom_equipment:[],published:false,vehicle_type:'',plate:'',make:'',model:'',trim:'',year:'',fuel:'',transmission:'',color:'',seats:'',doors:'',vin:'',equipment:[]};
const fuels:Record<string,string>={diesel:'Diesel',petrol:'Essence',hybrid:'Hybride',electric:'Électrique'};
const gears:Record<string,string>={manual:'Manuelle',automatic:'Automatique'};
const dailyRate=(v:number)=>new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(v/100)+' DH';
const km=(v:number|null)=>v===null?'Non renseigné':new Intl.NumberFormat('fr-MA').format(v)+' km';
const today=()=>moroccoDateTimeFormat('en-CA', {timeZone:'Africa/Casablanca',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const date=(s:string)=>new Intl.DateTimeFormat('fr-MA',{dateStyle:'medium'}).format(new Date(s+'T12:00:00'));
function toFields(v:Vehicle):Fields { return Object.fromEntries(Object.keys(empty).map(k=>[k,k==='published'?Boolean(v.published):k==='public_description'?Object.fromEntries(Object.keys(blankText()).map(lang=>[lang,v.public_description?.[lang as keyof Localized]??''])):k==='custom_equipment'?(v.custom_equipment??[]).map(item=>Object.fromEntries(Object.keys(blankText()).map(lang=>[lang,item?.[lang as keyof Localized]??'']))):k==='equipment'?v.equipment:String(v[k as keyof Fields]??'')])) as Fields; }
function CarIcon(){return <svg viewBox="0 0 80 40" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><path d="m10 27 3-10 13-3 9-8h20l11 11 8 3v10H7v-3h3Z"/><path d="m29 14 8-6h16l7 8H28m15-8v8M18 21h8m39 0h6"/><circle cx="22" cy="30" r="6" fill="var(--fleet-thumb,#252a2e)"/><circle cx="60" cy="30" r="6" fill="var(--fleet-thumb,#252a2e)"/></svg>}
function Cover({vehicle}:{vehicle:Vehicle}){
 const [url,setUrl]=useState('');
 useEffect(()=>{const abort=new AbortController();let objectUrl='';setUrl('');
 if(vehicle.cover_id) void fetch(api+'/api/v1/vehicles/'+vehicle.id+'/photos/'+vehicle.cover_id+'/content',{credentials:'include',cache:'no-store',signal:abort.signal})
 .then(r=>r.ok?r.blob():null).then(blob=>{if(blob&&!abort.signal.aborted){objectUrl=URL.createObjectURL(blob);setUrl(objectUrl);}}).catch(()=>{});
 return()=>{abort.abort();if(objectUrl)URL.revokeObjectURL(objectUrl);};},[vehicle.id,vehicle.cover_id]);
 return <span className="vehicle-thumb">{url?<img src={url} alt="" onError={()=>setUrl('')}/>:<CarIcon/>}</span>;
}
function checkSession(r:Response){if(r.status===401){window.location.replace('/login');throw new Error('Votre session a expiré.');}}
async function readDetails(id:number,signal?:AbortSignal):Promise<Details>{
 const r=await fetch(api+'/api/v1/vehicles/'+id,{credentials:'include',headers:{Accept:'application/json'},cache:'no-store',signal});checkSession(r);
 if(!r.ok)throw new Error('Le fichier n’a pas pu être ouvert. Réessayez.');return r.json();
}

export default function Vehicles(){
 const [search,setSearch]=useState(''),[make,setMake]=useState(''),[fuel,setFuel]=useState(''),[gear,setGear]=useState('');
 const [page,setPage]=useState(1),[reload,setReload]=useState(0),[listing,setListing]=useState<Listing|null>(null);
 const [loading,setLoading]=useState(true),[listError,setListError]=useState(''),[flash,setFlash]=useState('');
 const [editing,setEditing]=useState(false),[justCreated,setJustCreated]=useState(false);
 const [opened,setOpened]=useState(false),[opening,setOpening]=useState(false),[openFailed,setOpenFailed]=useState(false),[tab,setTab]=useState<Tab>('info');
 const [record,setRecord]=useState<Vehicle|null>(null),[form,setForm]=useState<Fields>({...empty}),[baseline,setBaseline]=useState<Fields>({...empty});
 const [creationFlow,setCreationFlow]=useState(false),[creationDocuments,setCreationDocuments]=useState<CreationDocuments>({});
 const tabs:[Tab,string][]=creationFlow?[...baseTabs,['documents','Documents']]:baseTabs;
 const lastTab=tabs[tabs.length-1][0];
 const [photosBusy,setPhotosBusy]=useState(false);
 const [photos,setPhotos]=useState<Photo[]>([]),[pending,setPending]=useState<Partial<Record<Slot,PendingPhoto>>>({});
 const [readings,setReadings]=useState<Reading[]>([]),[canCorrect,setCanCorrect]=useState(false);
 const [catalog,setCatalog]=useState<{id:number;name:string;labels:Localized}[]>([]),[equipmentBusy,setEquipmentBusy]=useState(false);
 const equipmentKey=(item:Localized)=>(item.fr||item.en||item.ar||item.es||'').trim().replace(/\s+/g,' ').toLocaleLowerCase('fr');
 const availableEquipment=[...catalog.map(e=>e.labels),...form.custom_equipment.filter(e=>!catalog.some(c=>equipmentKey(c.labels)===equipmentKey(e)))];
 useEffect(()=>{if(!opened)return;const abort=new AbortController();fetch(api+'/api/v1/vehicles/equipment-catalog',{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:abort.signal}).then(async r=>{if(!r.ok)throw new Error();const d=await r.json();setCatalog(d.data);}).catch(()=>{if(!abort.signal.aborted)setEquipmentError('Impossible de charger les équipements enregistrés. Rouvrez le formulaire pour réessayer.');});return()=>abort.abort();},[opened]);
 const [equipmentEdit,setEquipmentEdit]=useState<number|null>(null),[equipmentName,setEquipmentName]=useState(''),[equipmentManageError,setEquipmentManageError]=useState('');
 const [equipmentDraft,setEquipmentDraft]=useState(''),[equipmentError,setEquipmentError]=useState('');
 const [initialPrice,setInitialPrice]=useState(''),[pricingDirty,setPricingDirty]=useState(false),[pricingBusy,setPricingBusy]=useState(false);
 const [meter,setMeter]=useState(''),[meterDate,setMeterDate]=useState(''),[meterKind,setMeterKind]=useState('reading'),[reason,setReason]=useState('');
 const [error,setError]=useState(''),[errors,setErrors]=useState<Record<string,string[]>>({}),[saving,setSaving]=useState(false),[progress,setProgress]=useState('');
 const dialog=useRef<HTMLDialogElement>(null),formRef=useRef<HTMLFormElement>(null),saveLock=useRef(false),openSequence=useRef(0);
 const createKey=useRef(''),meterKey=useRef('');
 const openedFromLink=useRef(false);
 useEffect(()=>{
  if(openedFromLink.current)return;openedFromLink.current=true;
  const url=new URL(window.location.href),id=url.searchParams.get('vehicle');
  if(url.searchParams.get('action')==='create'||!id||!/^\d+$/.test(id)||!Number.isSafeInteger(Number(id))||Number(id)<1)return;
  url.searchParams.delete('vehicle');window.history.replaceState(window.history.state,'',url.pathname+url.search+url.hash);
  const documents=url.searchParams.get('section')==='documents';
  void open(Number(id)).then(()=>{if(documents)setJustCreated(true);});
 },[]);
 const dirty=JSON.stringify(form)!==JSON.stringify(baseline)||Object.keys(pending).length>0||meter!==''||pricingDirty||(!record&&initialPrice!=='')||Object.values(creationDocuments).some(d=>!d.saved);
 useEffect(()=>{const abort=new AbortController();setLoading(true);setListError('');
 const timer=setTimeout(async()=>{try{
 const r=await fetch(api+'/api/v1/vehicles?'+new URLSearchParams({q:search,make,fuel,transmission:gear,page:String(page)}),{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([abort.signal,AbortSignal.timeout(10000)])});checkSession(r);
 if(!r.ok)throw new Error('Impossible de charger les véhicules.');const data=await r.json();if(!abort.signal.aborted)setListing(data);
 }catch(e){if(!abort.signal.aborted){setListing(null);setListError(e instanceof Error?e.message:'Connexion impossible.');}}finally{if(!abort.signal.aborted)setLoading(false);}},200);
 return()=>{clearTimeout(timer);abort.abort();};},[search,make,fuel,gear,page,reload]);
 useEffect(()=>{if(opened&&!dialog.current?.open)dialog.current?.showModal();if(!opened&&dialog.current?.open)dialog.current.close();},[opened]);
 useEffect(()=>{dialog.current?.querySelector('.vehicle-body')?.scrollTo({top:0});},[tab]);
 useEffect(()=>{const warn=(e:BeforeUnloadEvent)=>{if(opened&&(dirty||saving)){e.preventDefault();e.returnValue='';}};window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[opened,dirty,saving]);
 function reset(){setCreationFlow(false);setCreationDocuments({});setEquipmentEdit(null);setEquipmentName('');setEquipmentManageError('');setEquipmentDraft('');setEquipmentError('');setInitialPrice('');setPricingDirty(false);setPricingBusy(false);setJustCreated(false);setEditing(false);setOpenFailed(false);setError('');setErrors({});setPending({});setPhotos([]);setReadings([]);setRecord(null);setForm({...empty});setBaseline({...empty});setMeter('');setMeterDate('');setReason('');setMeterKind('reading');setCanCorrect(false);setTab('info');createKey.current=crypto.randomUUID();meterKey.current=crypto.randomUUID();}
 function close(){if(saveLock.current||pricingBusy||photosBusy||equipmentBusy)return;if(dirty&&!window.confirm('Quitter sans enregistrer les modifications ?'))return;openSequence.current++;setOpened(false);}
 useCreateShortcut(create);
 function create(){openSequence.current++;reset();setCreationFlow(true);setEditing(true);setOpening(false);setOpened(true);}
 async function open(id:number,target?:Tab){
 const seq=++openSequence.current;reset();setOpening(true);setOpened(true);
 try{const d=await readDetails(id,AbortSignal.timeout(10000));if(seq!==openSequence.current)return;setRecord(d.data);setForm(toFields(d.data));setBaseline(toFields(d.data));setPhotos(d.photos);setReadings(d.readings);setCanCorrect(d.can_correct_odometer);if(target){setTab(target);setEditing(true);}}
 catch(e){if(seq===openSequence.current){setOpenFailed(true);setError(e instanceof Error?e.message:'Ouverture impossible.');}}
 finally{if(seq===openSequence.current)setOpening(false);}
 }
 async function refreshSummary(){if(!record)return;const seq=openSequence.current;try{const d=await readDetails(record.id,AbortSignal.timeout(10000));if(seq!==openSequence.current)return;setRecord(d.data);setForm(toFields(d.data));setBaseline(toFields(d.data));setReadings(d.readings);setReload(n=>n+1);}catch{setError("L’intervention est enregistrée. Rouvrez le fichier pour actualiser le compteur.");}}
 function edit(){setTab('info');setEditing(true);setTimeout(()=>formRef.current?.querySelector<HTMLInputElement>('input[name=plate]')?.focus(),0);}
 function cancelEdit(){
 if(saveLock.current||photosBusy||equipmentBusy)return;
 if(dirty&&!window.confirm('Annuler les modifications non enregistrées ?'))return;
 if(!record){close();return;}
 setForm(toFields(record));setBaseline(toFields(record));setPending({});setMeter('');setMeterDate('');setMeterKind('reading');setReason('');setError('');setErrors({});setEditing(false);
 dialog.current?.querySelector('.vehicle-body')?.scrollTo({top:0});
 }
 function setField(key:keyof Fields,value:string){setForm(v=>({...v,[key]:value}));setErrors(v=>({...v,[key]:[]}));}
 function revealErrors(errors:Record<string,string[]>){setErrors(errors);const first=Object.keys(errors)[0];setTab(first?.startsWith('document_')?'documents':['image','slot','upload_key','previous_id'].includes(first)?'photos':(first?.startsWith('equipment')||first?.startsWith('custom_equipment')||first?.startsWith('public_description'))?'equipment':['kilometers','recorded_on','reason','initial_odometer','initial_odometer_date'].includes(first)?'odometer':'info');setTimeout(()=>focusFieldError(formRef.current,errors),0);}
 async function addEquipment(){
 if(equipmentBusy)return;
 const name=equipmentDraft.trim().replace(/\s+/g,' ');
 if(!name){setEquipmentError('Saisissez le nom de l’équipement.');document.getElementById('new-vehicle-equipment')?.focus();return;}
 const key=name.toLocaleLowerCase('fr');
 if(equipmentOptions.some(([,label])=>label.toLocaleLowerCase('fr')===key)||availableEquipment.some(item=>equipmentKey(item)===key)){setEquipmentError('Cet équipement existe déjà. Cochez-le dans la liste ci-dessus.');return;}
 if(form.custom_equipment.length>=30){setEquipmentError('Sélectionnez au maximum 30 équipements supplémentaires.');return;}
 setEquipmentBusy(true);setEquipmentError('');
 try{
 const r=await apiMutation('vehicles/equipment-catalog','POST',{name});
 const d=await r.json();if(!r.ok)throw new Error(d.errors?.name?.[0]||d.message||'Impossible d’enregistrer cet équipement.');
 setCatalog(v=>v.some(e=>e.id===d.data.id)?v:[...v,d.data]);
 setForm(v=>({...v,custom_equipment:v.custom_equipment.some(e=>equipmentKey(e)===equipmentKey(d.data.labels))?v.custom_equipment:[...v.custom_equipment,d.data.labels]}));
 setEquipmentDraft('');
 }catch(e){setEquipmentError(e instanceof Error?e.message:'Connexion impossible. Réessayez.');}
 finally{setEquipmentBusy(false);}
 }
 async function manageEquipment(id:number,action:'rename'|'remove'){
 const entry=catalog.find(e=>e.id===id);if(!entry||equipmentBusy)return;
 if(action==='remove'&&!window.confirm('Retirer « '+entry.name+' » de la liste ? Les voitures déjà enregistrées conserveront cet équipement.'))return;
 if(action==='rename'&&!equipmentName.trim()){setEquipmentManageError('Saisissez le nom de l’équipement.');document.getElementById('equipment-rename')?.focus();return;}
 setEquipmentBusy(true);setEquipmentManageError('');
 try{const r=await apiMutation('vehicles/equipment-catalog/'+id,'POST',{action,expected_name:entry.name,...(action==='rename'?{name:equipmentName.trim()}:{})});const d=await r.json();if(!r.ok)throw new Error(d.errors?.name?.[0]||d.message||'Modification impossible.');setCatalog(d.data);setEquipmentEdit(null);setEquipmentName('');}
 catch(e){setEquipmentManageError(e instanceof Error?e.message:'Connexion impossible.');}
 finally{setEquipmentBusy(false);}
 }
 function moveStep(direction:1|-1){
 if(saving||opening||openFailed||photosBusy||equipmentBusy)return;
 const next=tabs[tabs.findIndex(([key])=>key===tab)+direction];
 if(!next)return;
 setTab(next[0]);
 requestAnimationFrame(()=>{
 dialog.current?.querySelector('.vehicle-body')?.scrollTo({top:0});
 document.getElementById('v-tab-'+next[0])?.focus();
 });
 }
 async function responseData(r:Response){
 checkSession(r);const body=await r.json().catch(()=>({}));
 if(!r.ok){if(body.errors){const mapped={...body.errors};if(mapped.initial_odometer){mapped.kilometers=mapped.initial_odometer;delete mapped.initial_odometer;}if(mapped.initial_odometer_date){mapped.recorded_on=mapped.initial_odometer_date;delete mapped.initial_odometer_date;}revealErrors(mapped);}
 throw new Error(body.message && !body.errors?body.message:body.errors?Object.values(body.errors).flat().join(' '):'Enregistrement impossible. Réessayez.');}return body;
 }
 async function save(e:FormEvent){
 e.preventDefault();if(!editing||saveLock.current||opening||openFailed||photosBusy||equipmentBusy)return;
 if(creationFlow&&tab!==lastTab){moveStep(1);return;}
 if(!formRef.current)return;const invalid={...validateFields(formRef.current),...validateCreationDocuments(creationDocuments),...Object.fromEntries(Object.entries(errors).filter(([key,value])=>key.startsWith("document_")&&key.endsWith("_file")&&value.length))};if(Object.keys(invalid).length){revealErrors(invalid);setError('Vérifiez les champs signalés ci-dessous.');return;}
 saveLock.current=true;setSaving(true);setError('');setErrors({});let saved=record;let committed=false;
 try{
 setProgress('Enregistrement du fichier…');
 const body={...form,...(!record&&initialPrice.trim()!==''?{initial_daily_centimes:parseDailyPrice(initialPrice)}:{}),...(record?{version:record.version}:{creation_key:createKey.current,...(meter!==''?{initial_odometer:Number(meter),initial_odometer_date:meterDate}:{})})};
 const result=await responseData(await apiMutation(record?'vehicles/'+record.id:'vehicles',record?'PUT':'POST',body));
 saved=result.data as Vehicle;setRecord(saved);setBaseline(toFields(saved));setForm(toFields(saved));committed=true;
 if(!record){setMeter('');setMeterDate('');}
 for(const [slot,photo] of Object.entries(pending) as [Slot,PendingPhoto][]){
 setProgress('Enregistrement : '+photoSlots.find(s=>s[0]===slot)?.[1]+'…');setTab('photos');
 const data=new FormData();data.append('image',photo.file);data.append('slot',slot);data.append('upload_key',photo.key);data.append('previous_id',photo.previousId===null?'':String(photo.previousId));
 let result;try{result=await responseData(await apiMutation('vehicles/'+saved.id+'/photos','POST',data));}catch(e){setErrors(v=>({...v,['photo_'+slot]:[e instanceof Error?e.message:'Envoi non confirmé. Réessayez.']}));throw e;}
 setPhotos(v=>[...v.filter(p=>p.slot!==slot),result.data]);setPending(v=>{const next={...v};delete next[slot];return next;});
 }
 if(record&&meter!==''){
 setProgress('Enregistrement du relevé…');setTab('odometer');
 const result=await responseData(await apiMutation('vehicles/'+saved.id+'/odometer','POST',{kilometers:Number(meter),recorded_on:meterDate,kind:meterKind,reason:reason||null,version:saved.version,request_key:meterKey.current}));
 saved=result.data;setRecord(saved);setMeter('');setMeterDate('');setReason('');meterKey.current=crypto.randomUUID();
 }
 for(const [kind,label] of creationDocumentKinds){
 const draft=creationDocuments[kind];if(!draft||draft.saved)continue;
 setProgress('Enregistrement : '+label+'…');setTab('documents');
 setCreationDocuments(v=>({...v,[kind]:{...draft,attempted:true}}));
 let r:Response;
 try{r=await apiMutation('vehicles/'+result.data.id+'/documents','POST',creationDocumentPayload(kind,draft));}
 catch{throw new Error(label+' : envoi non confirmé. Réessayez pour confirmer le même envoi, sans créer une autre pièce.');}
 checkSession(r);const payload=await r.json().catch(()=>({}));
 if(!r.ok){
 if(r.status===422){setCreationDocuments(v=>({...v,[kind]:{...draft,attempted:false}}));const mapped=Object.fromEntries(Object.entries(payload.errors??{file:['Vérifiez cette pièce.']}).map(([key,value])=>[documentField(kind,key),value])) as Record<string,string[]>;revealErrors(mapped);}
 throw new Error(payload.message??label+' : enregistrement non confirmé. Réessayez.');
 }
 setCreationDocuments(v=>({...v,[kind]:{...draft,attempted:true,saved:true}}));
 }
 setFlash(form.make+' '+form.model+' · fichier enregistré.');setReload(v=>v+1);if(creationFlow){await open(result.data.id);setJustCreated(true);}else setOpened(false);
 }catch(e){setError((committed?'Les informations sont enregistrées. Il reste à terminer : ':'')+(e instanceof Error?e.message:'Connexion impossible. Réessayez.'));if(committed)setReload(v=>v+1);}
 finally{saveLock.current=false;setSaving(false);setProgress('');}
 }
 function input(key:Exclude<keyof Fields,'equipment'|'published'|'public_description'|'custom_equipment'>,label:string,required=false,type='text',extra:Record<string,string|number>={}){
 return <label className={'v-field '+(key==='vin'?'v-full':'')} key={key}><span>{label}{required&&<b aria-label="obligatoire"> *</b>}<FieldHelp field={key} label={label}/></span><input aria-label={label} name={key} value={form[key]} onChange={e=>setField(key,e.target.value)} required={required} type={type} aria-invalid={!!errors[key]?.length} aria-describedby={errors[key]?.length?"v-error-"+key:undefined} {...extra}/>{errors[key]?.map(s=><small id={"v-error-"+key} className="v-error" key={s}>{s}</small>)}</label>;
 }
 function select(key:'vehicle_type'|'fuel'|'transmission'|'year'|'seats'|'doors',label:string,options:Record<string,string>){return <label className="v-field"><span id={"v-label-"+key}>{label}<FieldHelp field={key} label={label}/></span><select aria-label={label}  name={key} aria-invalid={!!errors[key]?.length} aria-describedby={errors[key]?.length?"v-error-"+key:undefined} value={form[key]} onChange={e=>setField(key,e.target.value)}><option value="">À renseigner</option>{(key==='year'?Object.entries(options).reverse():Object.entries(options)).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select>{errors[key]?.[0]&&<small id={"v-error-"+key} className="v-error">{errors[key][0]}</small>}</label>;}
 return <section className="fleet-shell">
 <header className="fleet-heading"><div><h1>Véhicules</h1><p>Informations, documents et suivi de votre parc.</p></div><div className="fleet-heading-actions"><ExcelExchange kind="vehicles" filters={{q:search,make,fuel,transmission:gear}} onImported={()=>setReload(v=>v+1)}/><button type="button" className="fleet-icon-action" aria-label="Actualiser le parc" title="Actualiser le parc" disabled={loading} onClick={()=>setReload(v=>v+1)}><RefreshCw size={18}/></button><button className="fleet-add" onClick={create}><Plus size={18}/>Ajouter un véhicule</button></div></header>
 <div className="fleet-shortcuts"><div className="fleet-total"><span className="fleet-shortcut-icon"><CarFront size={21}/></span><div><small>Véhicules enregistrés</small><strong>{listing?.fleet_total??'—'}</strong></div></div><a href="/maintenance"><span className="fleet-shortcut-icon"><Wrench size={20}/></span><span><strong>Entretien & suivi</strong><small>Travaux, garage et échéances</small></span></a><a href="/reservations"><span className="fleet-shortcut-icon"><CalendarDays size={20}/></span><span><strong>Réservations</strong><small>Consulter les dossiers de location</small></span></a></div>
 {flash&&<div className="v-success" role="status">{flash}<button type="button" aria-label="Fermer la confirmation" onClick={()=>setFlash('')}>×</button></div>}
 <div className="fleet-toolbar"><label className="fleet-search"><FieldHelp field={"search"} label={"Rechercher un véhicule"}/><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg><input data-validation-field="search" aria-label="Rechercher un véhicule" placeholder="Immatriculation, marque, modèle, référence…" value={search} onChange={e=>{setSearch(e.target.value);setPage(1);}} maxLength={100}/></label><div className="fleet-filters"><FieldHelp field="filter" label="Filtres des véhicules"/><select data-validation-field="make" aria-label="Filtrer par marque" value={make} onChange={e=>{setMake(e.target.value);setPage(1);}}><option value="">Toutes les marques</option>{listing?.makes.map(m=><option key={m}>{m}</option>)}</select><select data-validation-field="fuel" aria-label="Filtrer par carburant" value={fuel} onChange={e=>{setFuel(e.target.value);setPage(1);}}><option value="">Tous carburants</option>{Object.entries(fuels).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select><select data-validation-field="gear" aria-label="Filtrer par boîte" value={gear} onChange={e=>{setGear(e.target.value);setPage(1);}}><option value="">Toutes boîtes</option>{Object.entries(gears).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select></div></div>
 <div className="fleet-list-heading"><h2>Fichiers véhicules <span>{loading?'…':listing?.total??0}</span></h2>{(search||make||fuel||gear)&&<button type="button" className="text-button" onClick={()=>{setSearch('');setMake('');setFuel('');setGear('');setPage(1);}}>Effacer les filtres</button>}</div>
 {listError?<div className="v-empty" role="alert"><p>{listError}</p><button className="secondary" onClick={()=>setReload(v=>v+1)}>Réessayer</button></div>:loading?<div className="v-empty" role="status">Chargement du parc…</div>:listing?.data.length?<><div className="fleet-table-wrap"><table className="fleet-table"><thead><tr><th>Véhicule</th><th>Immatriculation</th><th>Motorisation</th><th>Compteur</th><th>Location / jour <FieldHelp field="vehicle_daily_price" label="Prix normal par jour" hover/></th><th><span className="sr-only">Actions</span></th></tr></thead><tbody>{listing.data.map(v=><tr key={v.id} onClick={event=>{if(!(event.target as Element).closest('button,a,input'))void open(v.id);}}><td><div className="vehicle-name"><Cover vehicle={v}/><span><strong>{v.make} {v.model}</strong><small>{v.year??'Année à renseigner'} · {v.reference}</small></span></div></td><td><span className="vehicle-plate">{v.plate}</span></td><td><div className="vehicle-spec">{fuels[v.fuel]??'À renseigner'}<small>{gears[v.transmission]??'Boîte à renseigner'}</small></div></td><td className="vehicle-km">{km(v.current_odometer)}<small>{v.current_odometer_date?`Relevé du ${date(v.current_odometer_date)}`:'Date non renseignée'}</small></td><td className="vehicle-daily-rate"><strong className={v.normal_daily_centimes==null?'missing':undefined}>{v.normal_daily_centimes==null?'À définir':dailyRate(v.normal_daily_centimes)}</strong><small>Tarif normal · hors caution</small></td><td><div className="fleet-row-actions"><button type="button" className="fleet-icon-action" onClick={()=>void open(v.id)} aria-label={'Ouvrir '+v.plate} title="Consulter le fichier"><Eye size={17}/></button><button type="button" className="fleet-icon-action" onClick={()=>void open(v.id,'info')} aria-label={'Modifier '+v.plate} title="Modifier les informations"><Pencil size={17}/></button><button type="button" className="fleet-icon-action" onClick={()=>void open(v.id,'photos')} aria-label={'Photos de '+v.plate} title="Gérer les photos"><Camera size={17}/></button></div></td></tr>)}</tbody></table></div><div className="fleet-pagination"><span>{listing.total} véhicule{listing.total>1?'s':''} · Page {listing.page} / {listing.last_page}</span><div><button className="secondary" disabled={page<=1} onClick={()=>setPage(v=>v-1)}>Précédent</button><button className="secondary" disabled={page>=listing.last_page} onClick={()=>setPage(v=>v+1)}>Suivant</button></div></div></>:<div className="v-empty"><CarIcon/><h3>{search||make||fuel||gear?'Aucun véhicule trouvé.':'Votre parc commence ici.'}</h3><p>{search||make||fuel||gear?'Essayez une autre recherche ou effacez les filtres.':'Ajoutez votre première voiture avec sa plaque, sa marque et son modèle.'}</p>{!search&&!make&&!fuel&&!gear&&<button className="primary" onClick={create}>Ajouter un véhicule</button>}</div>}
 <dialog ref={dialog} className={"vehicle-dialog"+(editing&&tab==='photos'?' vehicle-photos-step':'')} aria-labelledby="vehicle-title" onCancel={e=>{if(e.target!==e.currentTarget)return;e.preventDefault();close();}} onClose={e=>{if(e.target===e.currentTarget)setOpened(false);}}>
 <form ref={formRef} noValidate onSubmit={save}>
 <header className="vehicle-header"><div><span className="fleet-eyebrow">{record?record.reference:'NOUVEAU FICHIER'}</span><h2 id="vehicle-title">{record?record.make+' '+record.model:'Ajouter un véhicule'}</h2><p>{record?record.plate:'Ajoutez la voiture et, si vous les avez, ses documents.'}</p></div><button type="button" className="vehicle-close" aria-label="Fermer le fichier" disabled={saving||pricingBusy||photosBusy||equipmentBusy} onClick={close}>×</button></header>
 {editing&&<div className={"vehicle-tabs"+(creationFlow?" vehicle-creation-tabs":"")} role="tablist" aria-label="Sections du véhicule">{tabs.map(([key,label],i)=><button key={key} id={'v-tab-'+key} type="button" role="tab" aria-controls={'v-panel-'+key} aria-selected={tab===key} tabIndex={tab===key?0:-1} onClick={()=>setTab(key)} onKeyDown={e=>{if(e.key==='ArrowRight'||e.key==='ArrowLeft'){e.preventDefault();const next=tabs[(i+(e.key==='ArrowRight'?1:tabs.length-1))%tabs.length][0];setTab(next);document.getElementById('v-tab-'+next)?.focus();}}}><span className="v-tab-number">0{i+1}</span>{label}{key==='photos'&&(photos.length+Object.keys(pending).filter(s=>!photos.some(p=>p.slot===s)).length)>0&&<span className="v-tab-count">{new Set([...photos.map(p=>p.slot),...Object.keys(pending)]).size}</span>}</button>)}</div>}
 <div className="vehicle-body">
 {error&&<div className="v-error-box" role="alert">{error}</div>}
 {opening?<p role="status">Ouverture du fichier…</p>:!editing&&record?<VehicleSummary key={record.id} editDisabled={pricingBusy||pricingDirty} onEdit={section=>{if(pricingBusy||pricingDirty)return;setEditing(true);setTab(section);requestAnimationFrame(()=>document.getElementById('v-tab-'+section)?.focus());}} onChange={()=>void refreshSummary()} justCreated={justCreated} onPricingDirty={setPricingDirty} onPricingBusy={setPricingBusy} onPricingSaved={()=>setReload(n=>n+1)} vehicle={record} photos={photos} readings={readings} equipment={equipmentOptions} slots={photoSlots}/>:editing?<fieldset disabled={saving||openFailed} className="v-fieldset">
 <section id="v-panel-info" role="tabpanel" aria-labelledby="v-tab-info" hidden={tab!=='info'} data-panel="info">
 <div className="v-section-heading"><span>01</span><div><h3>Identifier la voiture</h3><p>Les champs marqués * sont obligatoires.</p></div></div>
 <label className="v-equipment"><input data-validation-field="published" type="checkbox" role="switch" checked={form.published} onChange={e=>setForm(v=>({...v,published:e.target.checked}))}/><span><strong>Visible sur le site</strong><small style={{display:'block'}}>Activez uniquement après avoir vérifié les vraies photos du véhicule. Sans photo valide, la voiture reste masquée.</small></span></label>
 <div className="v-grid v-essential">{input('plate','Immatriculation',true,'text',{maxLength:30,placeholder:'Ex. 12345-A-6'})}<MakeModelPicker key={record?.id ?? createKey.current} make={form.make} model={form.model} errors={errors} onChange={(make, model) => { setForm(v => ({ ...v, make, model })); setErrors(v => ({ ...v, make: [], model: [] })); }} /></div>
 {!record&&listing?.can_manage_pricing&&listing.pricing_ready&&<section className="v-price-create" aria-label="Prix de location à la création"><h3>Prix de location</h3><p>Le prix habituel pour 1 jour de location. Vous pourrez le modifier dans ce fichier ; les tarifs de vacances se règlent ensuite dans « Tarifs et saisons ».</p><label className="v-field"><span>Prix normal / jour (DH) <FieldHelp field="vehicle_daily_price" label="Prix de location" hover/></span><input aria-label="Prix de location par jour (DH)" name="initial_daily_centimes" inputMode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" placeholder="Ex. 300 · facultatif" value={initialPrice} onChange={e=>{setInitialPrice(e.target.value);setErrors(v=>({...v,initial_daily_centimes:[]}));}}/>{errors.initial_daily_centimes?.map(t=><small key={t} className="v-error">{t}</small>)}</label><p>Si le prix n’est pas encore décidé, laissez cette case vide. Aucun prix ne sera inventé.</p></section>}
 <details className="simple-section" open={['vehicle_type','trim','year','fuel','transmission','color','seats','doors','vin'].some(k=>errors[k]?.length)}><summary><span><strong>Caractéristiques de la voiture</strong><small>Facultatif · à compléter maintenant ou plus tard</small></span><span aria-hidden="true">⌄</span></summary><div className="simple-section-body"> <div className="v-section-heading"><span>02</span><div><h3>Caractéristiques</h3><p>Complétez maintenant ou revenez plus tard.</p></div></div>
 <div className="v-grid">{select('vehicle_type','Type de véhicule',vehicleTypes)}{input('trim','Finition / version',false,'text',{maxLength:100,placeholder:'Ex. Expression'})}{select('year','Année',Object.fromEntries(Array.from({length:new Date().getFullYear()+2-1950},(_,i)=>{const year=String(new Date().getFullYear()+1-i);return [year,year];})))}{select('fuel','Carburant',fuels)}{select('transmission','Boîte de vitesses',gears)}<ColorPicker key={"color-"+(record?.id ?? createKey.current)} value={form.color} onChange={value => setField("color", value)} errors={errors.color} /><SeatsPicker key={"seats-"+(record?.id ?? createKey.current)} value={form.seats} onChange={value=>setField("seats",value)} errors={errors.seats}/>{select('doors','Nombre de portes',{'2':'2 portes','3':'3 portes','4':'4 portes','5':'5 portes'})}{input('vin','Numéro de châssis',false,'text',{maxLength:17,placeholder:'17 caractères · facultatif'})}</div></div></details>
 </section>
 <section id="v-panel-equipment" role="tabpanel" aria-labelledby="v-tab-equipment" hidden={tab!=='equipment'} data-panel="equipment">
 <div className="v-section-heading"><span>02</span><div><h3>Ce que la voiture propose</h3><p>Cochez uniquement les équipements présents.</p></div><b className="v-selection-count">{form.equipment.length+form.custom_equipment.length} sélectionné{form.equipment.length+form.custom_equipment.length>1?'s':''}</b></div>
 <div className="v-equipment-grid">{equipmentOptions.map(([key,label])=><label className={'v-equipment '+(form.equipment.includes(key)?'selected':'')} key={key}><input aria-label={label} type="checkbox" checked={form.equipment.includes(key)} onChange={e=>setForm(v=>({...v,equipment:e.target.checked?[...v.equipment,key]:v.equipment.filter(k=>k!==key)}))}/><span>{label}<FieldHelp field="equipment" label={label}/></span></label>)}
 {availableEquipment.map((item,index)=>{const selected=form.custom_equipment.some(e=>equipmentKey(e)===equipmentKey(item));const entry=catalog.find(c=>equipmentKey(c.labels)===equipmentKey(item));return <div className="v-catalog-item" key={equipmentKey(item)}><label className={'v-equipment'+(selected?' selected':'')}><input id={'v-custom-equipment-'+index} type="checkbox" checked={selected} disabled={equipmentBusy||(!selected&&form.custom_equipment.length>=30)} aria-label={item.fr||item.ar||item.en||item.es} onChange={()=>setForm(v=>({...v,custom_equipment:selected?v.custom_equipment.filter(e=>equipmentKey(e)!==equipmentKey(item)):[...v.custom_equipment,item]}))}/><span dir="auto">{item.fr||item.ar||item.en||item.es}<small style={{display:'block'}}>{entry?'Équipement de l’agence':'Conservé sur cette voiture'}</small></span></label>{entry&&<div className="v-catalog-actions"><button type="button" disabled={equipmentBusy} onClick={()=>{setEquipmentEdit(entry.id);setEquipmentName(entry.name);setEquipmentManageError('');}} aria-label={'Modifier '+entry.name}>Modifier</button><button type="button" disabled={equipmentBusy} onClick={()=>manageEquipment(entry.id,'remove')} aria-label={'Retirer '+entry.name+' de la liste'}>Retirer de la liste</button></div>}</div>;})}
 </div>
 {equipmentEdit!==null&&<div className="v-public-content v-catalog-editor"><h3>Modifier le nom</h3><p>Les voitures déjà enregistrées conservent leur équipement actuel. Le nouveau nom sera proposé pour les prochains choix.</p><label className="v-field"><span>Nom de l’équipement</span><input id="equipment-rename" autoFocus maxLength={100} value={equipmentName} disabled={equipmentBusy} aria-invalid={!!equipmentManageError} aria-describedby={equipmentManageError?'equipment-manage-error':undefined} onChange={e=>{setEquipmentName(e.target.value);setEquipmentManageError('');}} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();manageEquipment(equipmentEdit,'rename');}}}/></label><div className="v-catalog-actions"><button type="button" disabled={equipmentBusy} onClick={()=>manageEquipment(equipmentEdit,'rename')}>{equipmentBusy?'Enregistrement…':'Enregistrer le nom'}</button><button type="button" disabled={equipmentBusy} onClick={()=>{setEquipmentEdit(null);setEquipmentManageError('');}}>Annuler</button></div></div>}
 {equipmentManageError&&<p className="v-error" id="equipment-manage-error" role="alert">{equipmentManageError}</p>}
 <div className="v-public-content"><h3>Ajouter un équipement</h3><div className="v-custom-add"><label className="v-field"><span>Nom de l’équipement</span><input id="new-vehicle-equipment" aria-label="Nom du nouvel équipement" maxLength={100} dir="auto" placeholder="Ex. Chargeur sans fil" value={equipmentDraft} aria-invalid={!!equipmentError} aria-describedby={equipmentError?'v-equipment-draft-error':undefined} onChange={e=>{setEquipmentDraft(e.target.value);setEquipmentError('');}} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();addEquipment();}}}/>{equipmentError&&<small id="v-equipment-draft-error" className="v-error" role="alert">{equipmentError}</small>}</label><button type="button" className="secondary" disabled={saving||equipmentBusy||form.custom_equipment.length>=30} onClick={addEquipment}><Plus size={16}/> {equipmentBusy?'Enregistrement…':'Ajouter'}</button></div><p>Enregistré dans la liste de l’agence dès l’ajout, même si vous fermez ce formulaire. Décochez-le pour le retirer uniquement de cette voiture.</p>
 <h3>Description pour le site</h3><p>Saisissez un seul texte. Il sera affiché tel quel dans toutes les langues du site.</p><label className="v-field"><span>Description · facultatif</span><textarea aria-label="Description pour le site" maxLength={3000} rows={4} dir="auto" value={form.public_description.fr||form.public_description.ar||form.public_description.en||form.public_description.es||''} onChange={e=>{const text=e.target.value;setForm(v=>({...v,public_description:{fr:text,ar:text,en:text,es:text}}));}}/></label></div></section>
 <section id="v-panel-photos" role="tabpanel" aria-labelledby="v-tab-photos" hidden={tab!=='photos'} data-panel="photos"><div className="v-section-heading"><span>03</span><div><h3>Photos du véhicule · Facultatif</h3></div></div><p className="v-photo-note">La photo principale sera affichée dans le catalogue si « Visible sur le site » est activé.</p><VehiclePhotoGallery key={record?.id??createKey.current} photos={photos} pending={pending} vehicleId={record?.id} disabled={saving||openFailed} errors={errors} onBusy={setPhotosBusy} onSaved={next=>{setPhotos(next);setReload(n=>n+1);}} onChange={next=>{setPending(next);setErrors(v=>Object.fromEntries(Object.entries(v).filter(([key])=>!key.startsWith('photo_'))));}}/></section>
 <section id="v-panel-odometer" role="tabpanel" aria-labelledby="v-tab-odometer" hidden={tab!=='odometer'} data-panel="odometer">
 <div className="v-meter-hero"><div><span>DERNIER COMPTEUR ENREGISTRÉ</span><strong>{km(record?.current_odometer??null)}</strong>{record?.current_odometer_date&&<small>Relevé du {date(record.current_odometer_date)}</small>}</div><svg width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><path d="M10 48a27 27 0 1 1 44 0M32 8v6M12 20l5 4m35-4-5 4M6 38h7m45 0h-7M32 39l13-17"/><circle cx="32" cy="39" r="4"/></svg></div>
 <div className="v-section-heading"><span>04</span><div><h3>{record?'Ajouter un relevé':'Le compteur à l’entrée du parc'}</h3><p>{record?'Le nouveau relevé s’ajoute à l’historique.':'Facultatif. Laissez vide si le kilométrage est inconnu.'}</p></div></div>
 {record&&canCorrect&&<label className="v-correction"><input aria-label={"Corriger une erreur de saisie"} type="checkbox" checked={meterKind==='correction'} onChange={e=>{setMeterKind(e.target.checked?'correction':'reading');meterKey.current=crypto.randomUUID();}}/> Corriger une erreur de saisie<FieldHelp field="odometer_correction" label="Corriger une erreur de saisie"/></label>}
 <div className="v-grid"><label className="v-field"><span>{meterKind==='correction'?'Kilométrage corrigé':'Kilométrage relevé'} (km)<FieldHelp field={"kilometers"} label="Kilométrage"/></span><input aria-label={meterKind==='correction'?'Kilométrage corrigé (km)':'Kilométrage relevé (km)'} name="kilometers" aria-invalid={!!errors.kilometers?.length} aria-describedby={errors.kilometers?.length?"v-error-kilometers":undefined} type="number" min="0" max="9999999" step="1" placeholder="Ex. 42500" value={meter} onChange={e=>{setMeter(e.target.value);setErrors(v=>({...v,kilometers:[]}));if(e.target.value&&!meterDate)setMeterDate(today());meterKey.current=crypto.randomUUID();}}/>{errors.kilometers?.map(s=><small id="v-error-kilometers" key={s} className="v-error">{s}</small>)}</label><label className="v-field"><span>Date du relevé{meter!==''?' *':''}<FieldHelp field={"recorded_on"} label={"Date du relevé"}/></span><input aria-label={"Date du relevé"} type="date" name="recorded_on" aria-invalid={!!errors.recorded_on?.length} aria-describedby={errors.recorded_on?.length?"v-error-recorded_on":undefined} value={meterDate} required={meter!==''} max={today()} min={record?.current_odometer_date??undefined} onChange={e=>{setMeterDate(e.target.value);meterKey.current=crypto.randomUUID();setErrors(v=>({...v,recorded_on:[]}));}}/>{errors.recorded_on?.[0]&&<small id="v-error-recorded_on" className="v-error">{errors.recorded_on[0]}</small>}</label>{record&&meterKind==='correction'&&<label className="v-field v-full"><span>Pourquoi cette correction ? *<FieldHelp field={"reason"} label={"Pourquoi cette correction ?"}/></span><textarea aria-label={"Pourquoi cette correction ?"} name="reason" aria-invalid={!!errors.reason?.length} aria-describedby={errors.reason?.length?"v-error-reason":undefined} value={reason} required={meter!==''} maxLength={500} placeholder="Expliquez l’erreur et la valeur vérifiée." onChange={e=>{setReason(e.target.value);meterKey.current=crypto.randomUUID();setErrors(v=>({...v,reason:[]}));}}/>{errors.reason?.[0]&&<small id="v-error-reason" className="v-error">{errors.reason[0]}</small>}</label>}</div>
 {record&&<div className="v-history"><h3>Historique des relevés <small>Les 50 derniers</small></h3>{readings.length?<ol>{readings.map(r=><li key={r.id}><span className={'v-reading-dot '+(r.kind==='correction'?'correction':'')}/><div><strong>{km(r.kilometers)}</strong><p>{date(r.recorded_on)} · {r.actor}</p>{r.reason&&<p>{r.reason}</p>}</div><span className="v-reading-kind">{r.kind==='initial'?'Initial':r.kind==='correction'?'Correction':'Relevé'}</span></li>)}</ol>:<p>Aucun relevé pour le moment.</p>}</div>}
 </section>
 {creationFlow&&<section id="v-panel-documents" role="tabpanel" aria-labelledby="v-tab-documents" hidden={tab!=='documents'} data-panel="documents"><VehicleCreateDocuments documents={creationDocuments} onChange={setCreationDocuments} errors={errors} onErrors={setErrors} today={today()}/></section>}
 </fieldset>:null}
 </div>
 <footer className="vehicle-footer"><div><span className={'v-save-dot '+(dirty?'pending':'')}/><span>{saving?progress:dirty?'Modifications à enregistrer':record?'Informations enregistrées · voir les contrôles du dossier':'Complétez à votre rythme'}</span></div><div><button className="secondary" type="button" disabled={saving||pricingBusy||photosBusy||equipmentBusy} onClick={editing&&record?cancelEdit:close}>{editing&&record?'Annuler':'Fermer'}</button>{editing&&tabs.findIndex(([key])=>key===tab)>0&&<button type="button" className="secondary" disabled={saving||opening||openFailed||photosBusy||equipmentBusy} onClick={()=>moveStep(-1)}>Précédent</button>}{editing&&tab!==lastTab&&<button type="button" className="v-save" disabled={saving||opening||openFailed||photosBusy||equipmentBusy} onClick={()=>moveStep(1)}>{tab==='photos'&&photos.length===0&&Object.keys(pending).length===0?'Passer cette étape':'Suivant'} <span aria-hidden="true">→</span></button>}{editing?(!creationFlow||tab===lastTab)&&<button type="submit" className="v-save" disabled={saving||opening||openFailed||photosBusy||equipmentBusy}>{saving?'Enregistrement…':creationFlow?(record?'Terminer l’enregistrement':Object.keys(creationDocuments).length?'Créer le véhicule':'Compléter plus tard'):'Enregistrer les modifications'}</button>:record&&<button type="button" className="v-save" disabled={pricingBusy||pricingDirty} onClick={e=>{e.preventDefault();edit();}}>Modifier le fichier <span aria-hidden="true">↗</span></button>}</div></footer>
 </form>
 </dialog>
 </section>;
}
