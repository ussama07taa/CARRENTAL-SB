'use client';
import {TravelDates} from '../public-site/travel-calendar';
import Fleet from '../public-site/fleet';
import CatalogueFilters from '../public-site/catalogue-filters';
import VehicleGallery,{DailyPrice,bookLabel,type PublicPhoto} from './vehicle-gallery';
import Link from 'next/link';
import {usePathname,useRouter} from 'next/navigation';
import {useEffect,useState,FormEvent} from 'react';
import {CalendarDays,ArrowUpRight,ArrowRight,Plane,MapPin,Users,Gauge,Check,ChevronDown,Menu,CarFront,ShieldCheck} from 'lucide-react';
import {copy,Lang} from './copy';
import './website.css';
import './catalogue.css';
import {whatsappHref,whatsappLabels} from '../../lib/whatsapp';
import {FloatingWhatsApp} from '../contact/whatsapp';
type Vehicle={id:number;make:string;model:string;year:number;fuel:string;transmission:string;seats:number;doors:number;equipment:string[];public_description?:Record<string,string>;custom_equipment?:Record<string,string>[];image:string|null;gallery:PublicPhoto[];daily_centimes:number|null;available:boolean|null;quote:{total_centimes:number;version_id:number}|null};
type Draft={starts_local:string;ends_local:string;pickup:string;dropoff:string;pickup_detail:string;dropoff_detail:string;flight:string;first_name:string;last_name:string;phone:string;email:string};
const initial:Draft={starts_local:'',ends_local:'',pickup:'airport',dropoff:'airport',pickup_detail:'',dropoff_detail:'',flight:'',first_name:'',last_name:'',phone:'',email:''};
export default function Website(){
 const path=usePathname(),router=useRouter(),parts=path.split('/').filter(Boolean),lang=(parts[0] in copy?parts[0]:'fr') as Lang,t=copy[lang],page=parts[1]??'home',vehicleId=Number(parts[2]);
 const [draft,setDraft]=useState<Draft>(initial),[hydrated,setHydrated]=useState(false),[vehicles,setVehicles]=useState<Vehicle[]>([]),[loading,setLoading]=useState(true),[error,setError]=useState(''),[submitError,setSubmitError]=useState(''),[ready,setReady]=useState(false),[demo,setDemo]=useState(false),[busy,setBusy]=useState(false),[reference,setReference]=useState(''),[filter,setFilter]=useState('all'),[menu,setMenu]=useState(false),[revision,setRevision]=useState(0);
 useEffect(()=>{try{const saved=sessionStorage.getItem('bensbih-website-draft');if(saved)setDraft({...initial,...JSON.parse(saved)});setReference(sessionStorage.getItem('bensbih-website-reference')??'');}catch{}setHydrated(true);},[]);
 useEffect(()=>{if(hydrated)sessionStorage.setItem('bensbih-website-draft',JSON.stringify(draft));},[draft,hydrated]);
 const [catalogSearch,setCatalogSearch]=useState('');
 useEffect(()=>{setCatalogSearch(new URLSearchParams(window.location.search).get('q')??'');},[]);
 const dateCopy={fr:{invalid:'Vos anciennes dates ne sont plus valides. Choisissez de nouvelles dates pour vérifier la disponibilité.',choose:'Choisir mes dates',all:'Tous les modèles',search:'Marque ou modèle'},en:{invalid:'Your saved dates are no longer valid. Choose new dates to check availability.',choose:'Choose my dates',all:'All models',search:'Make or model'},es:{invalid:'Las fechas guardadas ya no son válidas. Elige nuevas fechas para comprobar la disponibilidad.',choose:'Elegir fechas',all:'Todos los modelos',search:'Marca o modelo'},ar:{invalid:'المواعيد المحفوظة لم تعد صالحة. اختر مواعيد جديدة للتحقق من التوفر.',choose:'اختيار المواعيد',all:'جميع الموديلات',search:'العلامة أو الموديل'}}[lang];
 const [dateRejected,setDateRejected]=useState(false);
 const nowLocal=new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Casablanca',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date()).replace(' ','T');
 const hasDates=!!draft.starts_local||!!draft.ends_local;
 const dates=!!draft.starts_local&&!!draft.ends_local&&draft.starts_local>=nowLocal&&draft.ends_local>draft.starts_local;
 const invalidDates=hasDates&&!dates||dateRejected;
 useEffect(()=>{
  if(!hydrated)return;
  const controller=new AbortController();setLoading(true);setError('');setDateRejected(false);
  const query=dates?'?'+new URLSearchParams({starts_local:draft.starts_local,ends_local:draft.ends_local}):'';
  fetch('/api/public/vehicles'+query,{signal:controller.signal,cache:'no-store'}).then(async r=>{if(r.status===422){setDateRejected(true);const fallback=await fetch('/api/public/vehicles',{signal:controller.signal,cache:'no-store'});if(!fallback.ok)throw new Error();return fallback.json();}if(!r.ok)throw new Error();return r.json();}).then(d=>{setVehicles(d.data);setReady(d.ready);setDemo(d.demo);}).catch(e=>{if(e.name!=='AbortError'){setError(dates?t.error:t.offline);}}).finally(()=>{if(!controller.signal.aborted)setLoading(false);});
  return()=>controller.abort();
 },[hydrated,draft.starts_local,draft.ends_local,dates,revision,lang]);
 const set=(key:keyof Draft,value:string)=>setDraft(d=>({...d,[key]:value}));
 const money=(n:number)=>new Intl.NumberFormat(lang==='ar'?'ar-MA':lang==='en'?'en-GB':lang,{style:'currency',currency:'MAD',maximumFractionDigits:2}).format(n/100);
 const text=(key:string)=>t[key as keyof typeof t]??key;
 const selected=vehicles.find(v=>v.id===vehicleId);
 const href=(suffix='')=>'/'+lang+(suffix?'/'+suffix:'');
 const carImage=(v:Vehicle)=>v.image??'';
 const onSearch=(e:FormEvent)=>{e.preventDefault();if(page==='voitures')setRevision(n=>n+1);else router.push(href('voitures'));};
 const locationField=(key:'pickup'|'dropoff')=><label>{t[key]}<div className="ws-select"><MapPin size={17}/><select value={draft[key]} onChange={e=>set(key,e.target.value)}><option value="agency">{{fr:'Agence — Branes, Tanger',en:'Agency — Branes, Tangier',es:'Agencia — Branes, Tánger',ar:'الوكالة — برانص، طنجة'}[lang]}</option><option value="airport">{t.airport}</option><option value="hotel">{t.hotel}</option></select><ChevronDown size={15}/></div></label>;
 const dateFields=<TravelDates language={lang} start={draft.starts_local.split('T')[0].split('-').reverse().join('/')} end={draft.ends_local.split('T')[0].split('-').reverse().join('/')} startTime={draft.starts_local.split('T')[1]||'10:00'} endTime={draft.ends_local.split('T')[1]||'10:00'} onChange={(s,e,st,et)=>setDraft(d=>({...d,starts_local:s.split('/').reverse().join('-')+'T'+st,ends_local:e.split('/').reverse().join('-')+'T'+et}))}/>;
 const search=<form className="ws-search" onSubmit={onSearch}>{locationField('pickup')}{dateFields}<button className="ws-primary">{t.search}<ArrowRight size={19}/></button><small>{t.timezone} · {t.free}</small></form>;
 const cards=(items:Vehicle[])=><Fleet language={lang} vehicles={items} onChoose={(_,id)=>router.push(href('voitures/'+id))}/>;
 const status=loading?<p role="status" className="ws-status">{t.loading}</p>:error?<div className="ws-status" role="alert">{error}<button onClick={()=>setRevision(r=>r+1)}>{t.retry}</button></div>:null;
 async function submit(e:FormEvent<HTMLFormElement>){
  e.preventDefault();if(!selected?.quote||busy||loading||error)return;setBusy(true);setSubmitError('');
  let key=sessionStorage.getItem('bensbih-request-key');if(!key){key=crypto.randomUUID();sessionStorage.setItem('bensbih-request-key',key);}
  const data=new FormData(e.currentTarget);
  const payload={...draft,vehicle_id:selected.id,language:lang,request_key:key,quoted_centimes:selected.quote.total_centimes,quote_version:selected.quote.version_id,consent:data.get('consent')==='on',website:data.get('website')??''};
  // A retry keeps its key. Editing the payload creates a different intentional submission.
  const previous=sessionStorage.getItem('bensbih-request-payload');
  const signature=JSON.stringify({...payload,request_key:''});
  if(previous&&previous!==signature){key=crypto.randomUUID();payload.request_key=key;sessionStorage.setItem('bensbih-request-key',key);}
  sessionStorage.setItem('bensbih-request-payload',signature);
  try{
   const r=await fetch('/api/public/requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
   const result=await r.json();
   if(!r.ok){setSubmitError(r.status===409?t.changed:r.status===503?t.notready:t.error);return;}
   setReference(result.reference);sessionStorage.setItem('bensbih-website-reference',result.reference);
   sessionStorage.removeItem('bensbih-request-key');sessionStorage.removeItem('bensbih-request-payload');
   router.push(href('confirmation'));
  }catch{setSubmitError(t.error);}finally{setBusy(false);}
 }
 return <div className={page==='voitures'&&!vehicleId?'ws ws-fleet-page':'ws'} dir={lang==='ar'?'rtl':'ltr'}>
  {demo&&<div className="ws-demo">{t.demo}</div>}

  <main>
   {page==='voitures'&&!vehicleId&&<section className="ws-section ws-catalog"><div className="catalog-hero"><div className="catalog-hero-copy"><p className="ws-eyebrow">BEN SBIH / {t.cars}</p><h1>{t.fleet}</h1><p>{t.fleetSub}</p><span className="catalog-location"><MapPin size={16}/>{t.where}</span></div></div><details id="catalog-dates" className="catalog-dates"><summary><CalendarDays size={19}/>{dateCopy.choose}<ChevronDown size={18}/></summary>{search}</details>{invalidDates&&<p role="status" className="catalog-date-notice">{dateCopy.invalid}</p>}{status??<CatalogueFilters cars={vehicles} language={lang} dated={dates&&!dateRejected} search={catalogSearch} onSearch={setCatalogSearch} onChoose={(_,id)=>router.push(href('voitures/'+id))}/>}</section>}
   {(page==='voitures'||page==='demande')&&vehicleId>0&&<section className="ws-section"><Link className="ws-text-link" href={href('voitures')}>← {t.back}</Link>{(selected?<><div className="ws-detail-heading"><p className="ws-eyebrow">{page==='demande'?t.request:t.equipment}</p><h1>{selected.make} {selected.model}</h1><p>{page==='demande'?t.requestSub:t.notice}</p></div><div className="ws-detail-grid">
    <div>{page==='voitures'?<><VehicleGallery key={selected.id} photos={selected.gallery} name={selected.make+' '+selected.model} language={lang}/><DailyPrice value={selected.daily_centimes} language={lang}/><div className="ws-feature-grid"><span><Gauge/>{text(selected.transmission)}</span><span><Users/>{selected.seats} {t.seats}</span><span><CarFront/>{selected.doors} {t.doors}</span><span>{text(selected.fuel)}</span></div>{selected.public_description?.[lang]&&<p style={{whiteSpace:"pre-line"}}>{selected.public_description[lang]}</p>}{(selected.custom_equipment??[]).some(item=>item[lang])&&<ul>{selected.custom_equipment!.filter(item=>item[lang]).map((item,i)=><li key={i}>{item[lang]}</li>)}</ul>}<div className="ws-note"><ShieldCheck/><p>{t.notice}</p></div></>:<form className="ws-request-form" onSubmit={submit}>
     <h2>{t.request}</h2><div className="ws-form-grid">{(['first_name','last_name','phone','email'] as const).map((k,i)=><label key={k}>{[t.first,t.last,t.phone,t.email][i]}<input required={k!=='email'} autoComplete={['given-name','family-name','tel','email'][i]} type={k==='email'?'email':k==='phone'?'tel':'text'} maxLength={k==='phone'?20:k==='email'?254:100} value={draft[k]} onChange={e=>set(k,e.target.value)}/></label>)}{locationField('pickup')}{locationField('dropoff')}
     {draft.pickup==='hotel'&&<label>{t.hotelDetail}<input required maxLength={200} value={draft.pickup_detail} onChange={e=>set('pickup_detail',e.target.value)}/></label>}
     {draft.dropoff==='hotel'&&<label>{t.dropDetail}<input required maxLength={200} value={draft.dropoff_detail} onChange={e=>set('dropoff_detail',e.target.value)}/></label>}
     {(draft.pickup==='airport'||draft.dropoff==='airport')&&<label>{t.flight}<input maxLength={60} value={draft.flight} onChange={e=>set('flight',e.target.value)}/></label>}</div>
     <div className="ws-trap" aria-hidden="true"><label>Website<input name="website" tabIndex={-1} autoComplete="off"/></label></div>
     <label className="ws-consent"><input type="checkbox" name="consent" required/>{t.consent}</label><Link href={href('confidentialite')}>{t.privacy}</Link><p className="ws-note">{t.notice}</p>
     {!ready&&<p role="status">{t.notready}</p>}<button className="ws-primary" disabled={busy||loading||!!error||!ready||!selected.quote||selected.available===false}>{busy?t.sending:t.send}<ArrowRight size={18}/></button>
    </form>}</div>
    <aside className="ws-summary"><p className="ws-eyebrow">{t.quote}</p><form onSubmit={e=>{e.preventDefault();setRevision(r=>r+1);}}>{dateFields}<small>{t.timezone}</small></form><div className="ws-summary-price"><span>{t.total}</span><strong>{loading?'…':error?'—':selected.quote?money(selected.quote.total_centimes):'—'}</strong></div>{error&&<p role="alert">{error}</p>}<p className="ws-included"><Check size={17}/>{t.free} · {t.included}</p><p>{t.deposit}</p>{!selected.quote&&<p>{t.noquote}</p>}{selected.available===false&&<p role="alert">{t.unavailable}</p>}{page==='voitures'&&!loading&&!error&&<Link className="ws-primary" href={href('demande/'+selected.id)}>{bookLabel[lang]}<ArrowRight size={17}/></Link>}</aside>
    </div></>:(status??<p>{t.notfound}</p>))}</section>}
   {page==='confirmation'&&<section className="ws-confirm"><div className="ws-confirm-check"><Check size={35}/></div><p className="ws-eyebrow">BEN SBIH RENTAL CAR</p><h1>{reference?t.received:t.request}</h1><p>{reference?t.receivedText:t.notice}</p>{reference&&<div><small>{t.reference}</small><strong>{reference}</strong></div>}<p>{t.notice}</p><Link className="ws-primary" href={href('voitures')}>{t.back}<ArrowRight size={18}/></Link></section>}
   {['contact','conditions','confidentialite'].includes(page)&&<section className="ws-section ws-info"><p className="ws-eyebrow">BEN SBIH RENTAL CAR</p><h1>{page==='contact'?t.contact:page==='conditions'?t.terms:t.privacy}</h1><p>{page==='contact'?t.contactText:page==='conditions'?t.termsText:t.privacyText}</p><Link className="ws-primary" href={href('voitures')}>{t.reserve}<ArrowUpRight size={18}/></Link></section>}
  </main>
  {submitError&&page==='demande'&&<div className="ws-error" role="alert">{submitError}</div>}

 <FloatingWhatsApp language={lang}/>
 </div>;
}
