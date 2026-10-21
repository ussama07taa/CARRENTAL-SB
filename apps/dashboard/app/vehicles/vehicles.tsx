'use client';
import {useCreateShortcut} from '../_lib/use-create-shortcut';
import {validateFields,focusFieldError} from '../_lib/form-validation';
import FieldHelp from '../_components/field-help';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { api, apiMutation } from '../_lib/auth-client';
import SeatsPicker from './seats-picker';
import { vehicleTypes } from './vehicle-types';
import VehicleSummary from './vehicle-summary';
import {parseDailyPrice} from './vehicle-pricing';
import ColorPicker from './color-picker';
import MakeModelPicker from './make-model-picker';
import PhotoTile, { type PendingPhoto, type CustomerDocument } from '../clients/document-upload';

const equipmentOptions = [
 ['air_conditioning','Climatisation'],['bluetooth','Bluetooth'],['carplay','Apple CarPlay'],['android_auto','Android Auto'],
 ['usb','Prise USB'],['gps','GPS intégré'],['parking_sensors','Radar de recul'],['rear_camera','Caméra de recul'],
 ['cruise_control','Régulateur de vitesse'],['child_seat_mounts','Fixations ISOFIX'],['sunroof','Toit ouvrant'],
] as const;
const photoSlots = [['cover','Photo principale'],['front','Vue avant'],['rear','Vue arrière'],['interior','Habitacle'],['boot','Coffre'],['detail_1','Détail 1'],['detail_2','Détail 2'],['detail_3','Détail 3']] as const;
export type Slot = typeof photoSlots[number][0];
export type Photo = Omit<CustomerDocument,'slot'> & {slot: Slot};
type Fields = {vehicle_type:string;plate:string;make:string;model:string;trim:string;year:string;fuel:string;transmission:string;color:string;seats:string;doors:string;vin:string;equipment:string[]};
export type Vehicle = Omit<Fields,'year'|'seats'|'doors'> & {id:number;reference:string;version:number;year:number|null;seats:number|null;doors:number|null;current_odometer:number|null;current_odometer_date:string|null;cover_id?:number|null;normal_daily_centimes?:number|null};
export type Reading={id:number;kilometers:number;recorded_on:string;kind:string;reason:string|null;actor:string};
type Details={data:Vehicle;photos:Photo[];readings:Reading[];can_correct_odometer:boolean};
type Listing={can_manage_pricing:boolean;pricing_ready:boolean;data:Vehicle[];total:number;fleet_total:number;page:number;last_page:number;makes:string[]};
type Tab='info'|'equipment'|'photos'|'odometer';
const tabs: [Tab,string][]=[['info','Informations'],['equipment','Équipements'],['photos','Photos'],['odometer','Compteur']];
const empty:Fields={vehicle_type:'',plate:'',make:'',model:'',trim:'',year:'',fuel:'',transmission:'',color:'',seats:'',doors:'',vin:'',equipment:[]};
const fuels:Record<string,string>={diesel:'Diesel',petrol:'Essence',hybrid:'Hybride',electric:'Électrique'};
const gears:Record<string,string>={manual:'Manuelle',automatic:'Automatique'};
const dailyRate=(v:number)=>new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(v/100)+' DH';
const km=(v:number|null)=>v===null?'Non renseigné':new Intl.NumberFormat('fr-MA').format(v)+' km';
const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Casablanca',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const date=(s:string)=>new Intl.DateTimeFormat('fr-MA',{dateStyle:'medium'}).format(new Date(s+'T12:00:00'));
function toFields(v:Vehicle):Fields { return Object.fromEntries(Object.keys(empty).map(k=>[k,k==='equipment'?v.equipment:String(v[k as keyof Fields]??'')])) as Fields; }
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
 const [photos,setPhotos]=useState<Photo[]>([]),[pending,setPending]=useState<Partial<Record<Slot,PendingPhoto>>>({});
 const [readings,setReadings]=useState<Reading[]>([]),[canCorrect,setCanCorrect]=useState(false);
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
  void open(Number(id));
 },[]);
 const dirty=JSON.stringify(form)!==JSON.stringify(baseline)||Object.keys(pending).length>0||meter!==''||pricingDirty||(!record&&initialPrice!=='');
 useEffect(()=>{const abort=new AbortController();setLoading(true);setListError('');
 const timer=setTimeout(async()=>{try{
 const r=await fetch(api+'/api/v1/vehicles?'+new URLSearchParams({q:search,make,fuel,transmission:gear,page:String(page)}),{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([abort.signal,AbortSignal.timeout(10000)])});checkSession(r);
 if(!r.ok)throw new Error('Impossible de charger les véhicules.');const data=await r.json();if(!abort.signal.aborted)setListing(data);
 }catch(e){if(!abort.signal.aborted){setListing(null);setListError(e instanceof Error?e.message:'Connexion impossible.');}}finally{if(!abort.signal.aborted)setLoading(false);}},200);
 return()=>{clearTimeout(timer);abort.abort();};},[search,make,fuel,gear,page,reload]);
 useEffect(()=>{if(opened&&!dialog.current?.open)dialog.current?.showModal();if(!opened&&dialog.current?.open)dialog.current.close();},[opened]);
 useEffect(()=>{dialog.current?.querySelector('.vehicle-body')?.scrollTo({top:0});},[tab]);
 useEffect(()=>{const warn=(e:BeforeUnloadEvent)=>{if(opened&&(dirty||saving)){e.preventDefault();e.returnValue='';}};window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[opened,dirty,saving]);
 function reset(){setInitialPrice('');setPricingDirty(false);setPricingBusy(false);setJustCreated(false);setEditing(false);setOpenFailed(false);setError('');setErrors({});setPending({});setPhotos([]);setReadings([]);setRecord(null);setForm({...empty});setBaseline({...empty});setMeter('');setMeterDate('');setReason('');setMeterKind('reading');setCanCorrect(false);setTab('info');createKey.current=crypto.randomUUID();meterKey.current=crypto.randomUUID();}
 function close(){if(saveLock.current||pricingBusy)return;if(dirty&&!window.confirm('Quitter sans enregistrer les modifications ?'))return;openSequence.current++;setOpened(false);}
 useCreateShortcut(create);
 function create(){openSequence.current++;reset();setEditing(true);setOpening(false);setOpened(true);}
 async function open(id:number){
 const seq=++openSequence.current;reset();setOpening(true);setOpened(true);
 try{const d=await readDetails(id,AbortSignal.timeout(10000));if(seq!==openSequence.current)return;setRecord(d.data);setForm(toFields(d.data));setBaseline(toFields(d.data));setPhotos(d.photos);setReadings(d.readings);setCanCorrect(d.can_correct_odometer);}
 catch(e){if(seq===openSequence.current){setOpenFailed(true);setError(e instanceof Error?e.message:'Ouverture impossible.');}}
 finally{if(seq===openSequence.current)setOpening(false);}
 }
 async function refreshSummary(){if(!record)return;const seq=openSequence.current;try{const d=await readDetails(record.id,AbortSignal.timeout(10000));if(seq!==openSequence.current)return;setRecord(d.data);setForm(toFields(d.data));setBaseline(toFields(d.data));setReadings(d.readings);setReload(n=>n+1);}catch{setError("L’intervention est enregistrée. Rouvrez le fichier pour actualiser le compteur.");}}
 function edit(){setTab('info');setEditing(true);setTimeout(()=>formRef.current?.querySelector<HTMLInputElement>('input[name=plate]')?.focus(),0);}
 function cancelEdit(){
 if(saveLock.current)return;
 if(dirty&&!window.confirm('Annuler les modifications non enregistrées ?'))return;
 if(!record){close();return;}
 setForm(toFields(record));setBaseline(toFields(record));setPending({});setMeter('');setMeterDate('');setMeterKind('reading');setReason('');setError('');setErrors({});setEditing(false);
 dialog.current?.querySelector('.vehicle-body')?.scrollTo({top:0});
 }
 function setField(key:keyof Fields,value:string){setForm(v=>({...v,[key]:value}));setErrors(v=>({...v,[key]:[]}));}
 function revealErrors(errors:Record<string,string[]>){setErrors(errors);const first=Object.keys(errors)[0];setTab(['image','slot','upload_key','previous_id'].includes(first)?'photos':first?.startsWith('equipment')?'equipment':['kilometers','recorded_on','reason','initial_odometer','initial_odometer_date'].includes(first)?'odometer':'info');setTimeout(()=>focusFieldError(formRef.current,errors),0);}
 async function responseData(r:Response){
 checkSession(r);const body=await r.json().catch(()=>({}));
 if(!r.ok){if(body.errors){const mapped={...body.errors};if(mapped.initial_odometer){mapped.kilometers=mapped.initial_odometer;delete mapped.initial_odometer;}if(mapped.initial_odometer_date){mapped.recorded_on=mapped.initial_odometer_date;delete mapped.initial_odometer_date;}revealErrors(mapped);}
 throw new Error(body.message && !body.errors?body.message:body.errors?Object.values(body.errors).flat().join(' '):'Enregistrement impossible. Réessayez.');}return body;
 }
 async function save(e:FormEvent){
 e.preventDefault();if(!editing||saveLock.current||opening||openFailed)return;
 if(!formRef.current)return;const invalid=validateFields(formRef.current);if(Object.keys(invalid).length){revealErrors(invalid);setError('Vérifiez les champs signalés ci-dessous.');return;}
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
 setFlash(form.make+' '+form.model+' · fichier enregistré.');setReload(v=>v+1);if(!record){await open(result.data.id);setJustCreated(true);}else setOpened(false);
 }catch(e){setError((committed?'Les informations sont enregistrées. Il reste à terminer : ':'')+(e instanceof Error?e.message:'Connexion impossible. Réessayez.'));if(committed)setReload(v=>v+1);}
 finally{saveLock.current=false;setSaving(false);setProgress('');}
 }
 function input(key:Exclude<keyof Fields,'equipment'>,label:string,required=false,type='text',extra:Record<string,string|number>={}){
 return <label className={'v-field '+(key==='vin'?'v-full':'')} key={key}><span>{label}{required&&<b aria-label="obligatoire"> *</b>}<FieldHelp field={key} label={label}/></span><input aria-label={label} name={key} value={form[key]} onChange={e=>setField(key,e.target.value)} required={required} type={type} aria-invalid={!!errors[key]?.length} aria-describedby={errors[key]?.length?"v-error-"+key:undefined} {...extra}/>{errors[key]?.map(s=><small id={"v-error-"+key} className="v-error" key={s}>{s}</small>)}</label>;
 }
 function select(key:'vehicle_type'|'fuel'|'transmission'|'year'|'seats'|'doors',label:string,options:Record<string,string>){return <label className="v-field"><span id={"v-label-"+key}>{label}<FieldHelp field={key} label={label}/></span><select aria-label={label}  name={key} aria-invalid={!!errors[key]?.length} aria-describedby={errors[key]?.length?"v-error-"+key:undefined} value={form[key]} onChange={e=>setField(key,e.target.value)}><option value="">À renseigner</option>{(key==='year'?Object.entries(options).reverse():Object.entries(options)).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select>{errors[key]?.[0]&&<small id={"v-error-"+key} className="v-error">{errors[key][0]}</small>}</label>;}
 return <section className="fleet-shell">
 <header className="fleet-hero"><div><span className="fleet-eyebrow">BENSEBIHI / LE PARC</span><h1>Votre parc automobile.<br/><span>Chaque voiture, son fichier.</span></h1><p>Retrouvez ses informations, son prix de location et son kilométrage.</p><button className="fleet-add" onClick={create}><span aria-hidden="true">＋</span> Ajouter un véhicule</button></div><div className="fleet-count"><span>VÉHICULES ENREGISTRÉS</span><strong>{listing?.fleet_total??'—'}</strong><span>Votre parc, au même endroit.</span><div className="fleet-line-car"><CarIcon/></div></div></header>
 {flash&&<div className="v-success" role="status">{flash}<button type="button" aria-label="Fermer la confirmation" onClick={()=>setFlash('')}>×</button></div>}
 <div className="fleet-toolbar"><label className="fleet-search"><FieldHelp field={"search"} label={"Rechercher un véhicule"}/><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg><input aria-label="Rechercher un véhicule" placeholder="Immatriculation, marque, modèle, référence…" value={search} onChange={e=>{setSearch(e.target.value);setPage(1);}} maxLength={100}/></label><div className="fleet-filters"><FieldHelp field="filter" label="Filtres des véhicules"/><select aria-label="Filtrer par marque" value={make} onChange={e=>{setMake(e.target.value);setPage(1);}}><option value="">Toutes les marques</option>{listing?.makes.map(m=><option key={m}>{m}</option>)}</select><select aria-label="Filtrer par carburant" value={fuel} onChange={e=>{setFuel(e.target.value);setPage(1);}}><option value="">Tous carburants</option>{Object.entries(fuels).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select><select aria-label="Filtrer par boîte" value={gear} onChange={e=>{setGear(e.target.value);setPage(1);}}><option value="">Toutes boîtes</option>{Object.entries(gears).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select></div></div>
 <div className="fleet-list-heading"><h2>Fichiers véhicules <span>{loading?'…':listing?.total??0}</span></h2>{(search||make||fuel||gear)&&<button type="button" className="text-button" onClick={()=>{setSearch('');setMake('');setFuel('');setGear('');setPage(1);}}>Effacer les filtres</button>}</div>
 {listError?<div className="v-empty" role="alert"><p>{listError}</p><button className="secondary" onClick={()=>setReload(v=>v+1)}>Réessayer</button></div>:loading?<div className="v-empty" role="status">Chargement du parc…</div>:listing?.data.length?<><div className="fleet-table-wrap"><table className="fleet-table"><thead><tr><th>Véhicule</th><th>Immatriculation</th><th>Motorisation</th><th>Compteur</th><th>Location / jour <FieldHelp field="vehicle_daily_price" label="Prix normal par jour" hover/></th><th><span className="sr-only">Actions</span></th></tr></thead><tbody>{listing.data.map(v=><tr key={v.id}><td><div className="vehicle-name"><Cover vehicle={v}/><span><strong>{v.make} {v.model}</strong><small>{v.year??'Année à renseigner'} · {v.reference}</small></span></div></td><td><span className="vehicle-plate">{v.plate}</span></td><td><div className="vehicle-spec">{fuels[v.fuel]??'À renseigner'}<small>{gears[v.transmission]??'Boîte à renseigner'}</small></div></td><td className="vehicle-km">{km(v.current_odometer)}<small>{v.current_odometer_date?`Relevé du ${date(v.current_odometer_date)}`:'Date non renseignée'}</small></td><td className="vehicle-daily-rate"><strong className={v.normal_daily_centimes==null?'missing':undefined}>{v.normal_daily_centimes==null?'À définir':dailyRate(v.normal_daily_centimes)}</strong><small>Tarif normal · hors caution</small></td><td><button type="button" className="fleet-open" onClick={()=>void open(v.id)} aria-label={'Ouvrir '+v.plate}>Ouvrir <span aria-hidden="true">↗</span></button></td></tr>)}</tbody></table></div><div className="fleet-pagination"><span>{listing.total} véhicule{listing.total>1?'s':''} · Page {listing.page} / {listing.last_page}</span><div><button className="secondary" disabled={page<=1} onClick={()=>setPage(v=>v-1)}>Précédent</button><button className="secondary" disabled={page>=listing.last_page} onClick={()=>setPage(v=>v+1)}>Suivant</button></div></div></>:<div className="v-empty"><CarIcon/><h3>{search||make||fuel||gear?'Aucun véhicule trouvé.':'Votre parc commence ici.'}</h3><p>{search||make||fuel||gear?'Essayez une autre recherche ou effacez les filtres.':'Ajoutez votre première voiture avec sa plaque, sa marque et son modèle.'}</p>{!search&&!make&&!fuel&&!gear&&<button className="primary" onClick={create}>Ajouter un véhicule</button>}</div>}
 <dialog ref={dialog} className="vehicle-dialog" aria-labelledby="vehicle-title" onCancel={e=>{e.preventDefault();close();}} onClose={e=>{if(e.target===e.currentTarget)setOpened(false);}}>
 <form ref={formRef} noValidate onSubmit={save}>
 <header className="vehicle-header"><div><span className="fleet-eyebrow">{record?record.reference:'NOUVEAU FICHIER'}</span><h2 id="vehicle-title">{record?record.make+' '+record.model:'Ajouter un véhicule'}</h2><p>{record?record.plate:'Enregistrez la voiture, puis ajoutez son assurance et ses documents.'}</p></div><button type="button" className="vehicle-close" aria-label="Fermer le fichier" disabled={saving||pricingBusy} onClick={close}>×</button></header>
 {editing&&<div className="vehicle-tabs" role="tablist" aria-label="Sections du véhicule">{tabs.map(([key,label],i)=><button key={key} id={'v-tab-'+key} type="button" role="tab" aria-controls={'v-panel-'+key} aria-selected={tab===key} tabIndex={tab===key?0:-1} onClick={()=>setTab(key)} onKeyDown={e=>{if(e.key==='ArrowRight'||e.key==='ArrowLeft'){e.preventDefault();const next=tabs[(i+(e.key==='ArrowRight'?1:3))%4][0];setTab(next);document.getElementById('v-tab-'+next)?.focus();}}}><span className="v-tab-number">0{i+1}</span>{label}{key==='photos'&&(photos.length+Object.keys(pending).filter(s=>!photos.some(p=>p.slot===s)).length)>0&&<span className="v-tab-count">{new Set([...photos.map(p=>p.slot),...Object.keys(pending)]).size}</span>}</button>)}</div>}
 <div className="vehicle-body">
 {error&&<div className="v-error-box" role="alert">{error}</div>}
 {opening?<p role="status">Ouverture du fichier…</p>:!editing&&record?<VehicleSummary onChange={()=>void refreshSummary()} justCreated={justCreated} onPricingDirty={setPricingDirty} onPricingBusy={setPricingBusy} onPricingSaved={()=>setReload(n=>n+1)} vehicle={record} photos={photos} readings={readings} equipment={equipmentOptions} slots={photoSlots}/>:editing?<fieldset disabled={saving||openFailed} className="v-fieldset">
 <section id="v-panel-info" role="tabpanel" aria-labelledby="v-tab-info" hidden={tab!=='info'} data-panel="info">
 <div className="v-section-heading"><span>01</span><div><h3>Identifier la voiture</h3><p>Les champs marqués * sont obligatoires.</p></div></div>
 <div className="v-grid v-essential">{input('plate','Immatriculation',true,'text',{maxLength:30,placeholder:'Ex. 12345-A-6'})}<MakeModelPicker key={record?.id ?? createKey.current} make={form.make} model={form.model} errors={errors} onChange={(make, model) => { setForm(v => ({ ...v, make, model })); setErrors(v => ({ ...v, make: [], model: [] })); }} /></div>
 {!record&&listing?.can_manage_pricing&&listing.pricing_ready&&<section className="v-price-create" aria-label="Prix de location à la création"><h3>Prix de location</h3><p>Le prix habituel pour 1 jour de location. Vous pourrez le modifier dans ce fichier ; les tarifs de vacances se règlent ensuite dans « Tarifs et saisons ».</p><label className="v-field"><span>Prix normal / jour (DH) <FieldHelp field="vehicle_daily_price" label="Prix de location" hover/></span><input aria-label="Prix de location par jour (DH)" name="initial_daily_centimes" inputMode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" placeholder="Ex. 300 · facultatif" value={initialPrice} onChange={e=>{setInitialPrice(e.target.value);setErrors(v=>({...v,initial_daily_centimes:[]}));}}/>{errors.initial_daily_centimes?.map(t=><small key={t} className="v-error">{t}</small>)}</label><p>Si le prix n’est pas encore décidé, laissez cette case vide. Aucun prix ne sera inventé.</p></section>}
 <div className="v-section-heading"><span>02</span><div><h3>Caractéristiques</h3><p>Complétez maintenant ou revenez plus tard.</p></div></div>
 <div className="v-grid">{select('vehicle_type','Type de véhicule',vehicleTypes)}{input('trim','Finition / version',false,'text',{maxLength:100,placeholder:'Ex. Expression'})}{select('year','Année',Object.fromEntries(Array.from({length:new Date().getFullYear()+2-1950},(_,i)=>{const year=String(new Date().getFullYear()+1-i);return [year,year];})))}{select('fuel','Carburant',fuels)}{select('transmission','Boîte de vitesses',gears)}<ColorPicker key={"color-"+(record?.id ?? createKey.current)} value={form.color} onChange={value => setField("color", value)} errors={errors.color} /><SeatsPicker key={"seats-"+(record?.id ?? createKey.current)} value={form.seats} onChange={value=>setField("seats",value)} errors={errors.seats}/>{select('doors','Nombre de portes',{'2':'2 portes','3':'3 portes','4':'4 portes','5':'5 portes'})}{input('vin','Numéro de châssis',false,'text',{maxLength:17,placeholder:'17 caractères · facultatif'})}</div>
 </section>
 <section id="v-panel-equipment" role="tabpanel" aria-labelledby="v-tab-equipment" hidden={tab!=='equipment'} data-panel="equipment"><div className="v-section-heading"><span>03</span><div><h3>Ce que la voiture propose</h3><p>Cochez uniquement les équipements présents.</p></div><b className="v-selection-count">{form.equipment.length} sélectionné{form.equipment.length>1?'s':''}</b></div><div className="v-equipment-grid">{equipmentOptions.map(([key,label])=><label className={'v-equipment '+(form.equipment.includes(key)?'selected':'')} key={key}><input aria-label={label} type="checkbox" checked={form.equipment.includes(key)} onChange={e=>setForm(v=>({...v,equipment:e.target.checked?[...v.equipment,key]:v.equipment.filter(k=>k!==key)}))}/><span>{label}<FieldHelp field={"equipment"} label={label}/></span></label>)}</div><p className="v-footnote">Aucun équipement n’est ajouté automatiquement à un nouveau véhicule.</p></section>
 <section id="v-panel-photos" role="tabpanel" aria-labelledby="v-tab-photos" hidden={tab!=='photos'} data-panel="photos"><div className="v-section-heading"><span>04</span><div><h3>Les photos du véhicule</h3><p>JPG, PNG ou WebP · 5 Mo maximum par photo.</p></div></div><p className="v-photo-note">La photo principale représente la voiture dans votre parc. Ces images restent dans l’espace de l’agence ; elles ne sont pas publiées sur le site.</p><div className="v-photo-grid">{photoSlots.map(([slot,label])=><PhotoTile<Slot> key={slot} slot={slot} label={label} serverError={errors['photo_'+slot]?.[0]} collection="vehicles" customerId={record?.id} record={photos.find(p=>p.slot===slot)} pending={pending[slot]} editable disabled={saving} onSelect={(slot,file)=>{setErrors(v=>({...v,['photo_'+slot]:[]}));setPending(v=>({...v,[slot]:{file,key:crypto.randomUUID(),previousId:photos.find(p=>p.slot===slot)?.id??null}}));}} onRemove={slot=>setPending(v=>{const next={...v};delete next[slot];return next;})}/>)}</div></section>
 <section id="v-panel-odometer" role="tabpanel" aria-labelledby="v-tab-odometer" hidden={tab!=='odometer'} data-panel="odometer">
 <div className="v-meter-hero"><div><span>DERNIER COMPTEUR ENREGISTRÉ</span><strong>{km(record?.current_odometer??null)}</strong>{record?.current_odometer_date&&<small>Relevé du {date(record.current_odometer_date)}</small>}</div><svg width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><path d="M10 48a27 27 0 1 1 44 0M32 8v6M12 20l5 4m35-4-5 4M6 38h7m45 0h-7M32 39l13-17"/><circle cx="32" cy="39" r="4"/></svg></div>
 <div className="v-section-heading"><span>05</span><div><h3>{record?'Ajouter un relevé':'Le compteur à l’entrée du parc'}</h3><p>{record?'Le nouveau relevé s’ajoute à l’historique.':'Facultatif. Laissez vide si le kilométrage est inconnu.'}</p></div></div>
 {record&&canCorrect&&<label className="v-correction"><input aria-label={"Corriger une erreur de saisie"} type="checkbox" checked={meterKind==='correction'} onChange={e=>{setMeterKind(e.target.checked?'correction':'reading');meterKey.current=crypto.randomUUID();}}/> Corriger une erreur de saisie<FieldHelp field="odometer_correction" label="Corriger une erreur de saisie"/></label>}
 <div className="v-grid"><label className="v-field"><span>{meterKind==='correction'?'Kilométrage corrigé':'Kilométrage relevé'} (km)<FieldHelp field={"kilometers"} label="Kilométrage"/></span><input aria-label={meterKind==='correction'?'Kilométrage corrigé (km)':'Kilométrage relevé (km)'} name="kilometers" aria-invalid={!!errors.kilometers?.length} aria-describedby={errors.kilometers?.length?"v-error-kilometers":undefined} type="number" min="0" max="9999999" step="1" placeholder="Ex. 42500" value={meter} onChange={e=>{setMeter(e.target.value);setErrors(v=>({...v,kilometers:[]}));if(e.target.value&&!meterDate)setMeterDate(today());meterKey.current=crypto.randomUUID();}}/>{errors.kilometers?.map(s=><small id="v-error-kilometers" key={s} className="v-error">{s}</small>)}</label><label className="v-field"><span>Date du relevé{meter!==''?' *':''}<FieldHelp field={"recorded_on"} label={"Date du relevé"}/></span><input aria-label={"Date du relevé"} type="date" name="recorded_on" aria-invalid={!!errors.recorded_on?.length} aria-describedby={errors.recorded_on?.length?"v-error-recorded_on":undefined} value={meterDate} required={meter!==''} max={today()} min={record?.current_odometer_date??undefined} onChange={e=>{setMeterDate(e.target.value);meterKey.current=crypto.randomUUID();setErrors(v=>({...v,recorded_on:[]}));}}/>{errors.recorded_on?.[0]&&<small id="v-error-recorded_on" className="v-error">{errors.recorded_on[0]}</small>}</label>{record&&meterKind==='correction'&&<label className="v-field v-full"><span>Pourquoi cette correction ? *<FieldHelp field={"reason"} label={"Pourquoi cette correction ?"}/></span><textarea aria-label={"Pourquoi cette correction ?"} name="reason" aria-invalid={!!errors.reason?.length} aria-describedby={errors.reason?.length?"v-error-reason":undefined} value={reason} required={meter!==''} maxLength={500} placeholder="Expliquez l’erreur et la valeur vérifiée." onChange={e=>{setReason(e.target.value);meterKey.current=crypto.randomUUID();setErrors(v=>({...v,reason:[]}));}}/>{errors.reason?.[0]&&<small id="v-error-reason" className="v-error">{errors.reason[0]}</small>}</label>}</div>
 {record&&<div className="v-history"><h3>Historique des relevés <small>Les 50 derniers</small></h3>{readings.length?<ol>{readings.map(r=><li key={r.id}><span className={'v-reading-dot '+(r.kind==='correction'?'correction':'')}/><div><strong>{km(r.kilometers)}</strong><p>{date(r.recorded_on)} · {r.actor}</p>{r.reason&&<p>{r.reason}</p>}</div><span className="v-reading-kind">{r.kind==='initial'?'Initial':r.kind==='correction'?'Correction':'Relevé'}</span></li>)}</ol>:<p>Aucun relevé pour le moment.</p>}</div>}
 </section>
 </fieldset>:null}
 </div>
 <footer className="vehicle-footer"><div><span className={'v-save-dot '+(dirty?'pending':'')}/><span>{saving?progress:dirty?'Modifications à enregistrer':record?'Informations enregistrées · voir les contrôles du dossier':'Complétez à votre rythme'}</span></div><div><button className="secondary" type="button" disabled={saving||pricingBusy} onClick={editing&&record?cancelEdit:close}>{editing&&record?'Annuler':'Fermer'}</button>{editing?<button type="submit" className="v-save" disabled={saving||opening||openFailed}>{saving?'Enregistrement…':record?'Enregistrer les modifications':'Créer le fichier'}</button>:record&&<button type="button" className="v-save" disabled={pricingBusy||pricingDirty} onClick={e=>{e.preventDefault();edit();}}>Modifier le fichier <span aria-hidden="true">↗</span></button>}</div></footer>
 </form>
 </dialog>
 </section>;
}
