'use client';

import {useEffect,useRef,useState,type FormEvent} from 'react';
import {ArrowUpRight,ArrowRight,Phone,MessageCircle,Menu,X,MapPin,CalendarDays,ShieldCheck,Globe,ChevronDown} from 'lucide-react';
import '@fontsource-variable/manrope';
import '@fontsource-variable/noto-sans-arabic';
import {DayPicker} from 'react-day-picker';
import {fr,ar,enUS,es} from 'react-day-picker/locale';
import 'react-day-picker/style.css';
import './hero.css';
import './header.css';
import './home-hero.css';
import './header-reference.css';
import {whatsappHref} from '../../lib/whatsapp';
import CurrencyMenu from '../website/currency-menu';
import HeaderFlag from '../website/header-flag';
import {Check} from 'lucide-react';
import {FloatingWhatsApp} from '../contact/whatsapp';
import {TravelDates} from './travel-calendar';
import WhyBenSbih from './why-ben-sbih';
import Fleet,{SelectedCar} from './fleet';
import VehicleDetail,{detailCopy} from './vehicle-detail';
const copy={
 fr:{eyebrow:'LOCATION DE VOITURES À TANGER',title:'La route commence ici.',benefit:'Retrouvez votre voiture à l’aéroport ou à votre hôtel, aux dates qui vous conviennent.',search:'Voir les disponibilités',booking:'Votre réservation',hint:'Choisissez votre lieu, puis vos dates.',place:'Lieu de prise en charge',airport:'Tanger Aéroport',city:'Tanger Ville',hotel:'Hôtel',departure:'Départ',return:'Retour',date:'Date',time:'Heure',delivery:'Livraison Aéroport / Hôtel',terms:'Assurance et conditions à confirmer',format:'Saisissez une date valide au format jj/mm/aaaa et une heure au format 24 h.',invalid:'Le retour doit être postérieur au départ.',past:'Choisissez un départ à venir.',ready:'Recherche prête. Aperçu uniquement : aucune disponibilité réelle ni réservation n’a été confirmée.',illustration:'Visuel de présentation',menu:'Menu',close:'Fermer le menu',language:'Langue',home:'Accueil',cars:'Notre flotte',contact:'Contact',timezone:'Heure locale de Tanger'},
 ar:{eyebrow:'كراء السيارات في طنجة',title:'من هنا تبدأ رحلتك.',benefit:'استلم سيارتك من مطار طنجة أو من فندقك، في الموعد الذي يناسبك.',search:'عرض السيارات المتاحة',booking:'حجزك',hint:'اختر مكان الاستلام، ثم المواعيد.',place:'مكان استلام السيارة',airport:'مطار طنجة',city:'وسط طنجة',hotel:'الفندق',departure:'الذهاب',return:'العودة',date:'التاريخ',time:'الساعة',delivery:'التوصيل إلى المطار / الفندق',terms:'التأمين والشروط في انتظار التأكيد',format:'أدخل تاريخاً صحيحاً بصيغة يوم/شهر/سنة وساعة بصيغة 24 ساعة.',invalid:'يجب أن تكون العودة بعد موعد الذهاب.',past:'اختر موعد ذهاب في المستقبل.',ready:'البحث جاهز. هذه معاينة فقط: لم يتم تأكيد توفر السيارات أو أي حجز.',illustration:'صورة توضيحية',menu:'القائمة',close:'إغلاق القائمة',language:'اللغة',home:'الرئيسية',cars:'أسطولنا',contact:'التواصل',timezone:'التوقيت المحلي لطنجة'},
 en:{eyebrow:'CAR RENTAL IN TANGIER',title:'Your journey starts here.',benefit:'Pick up your car at Tangier airport or your hotel, on the dates that suit you.',search:'Check availability',booking:'Your reservation',hint:'Choose your location, then your dates.',place:'Pick-up location',airport:'Tangier Airport',city:'Tangier City',hotel:'Hotel',departure:'Pick-up',return:'Return',date:'Date',time:'Time',delivery:'Airport / Hotel delivery',terms:'Insurance and terms to be confirmed',format:'Enter a valid date as dd/mm/yyyy and a 24-hour time.',invalid:'Return must be after pick-up.',past:'Choose a future pick-up time.',ready:'Search ready. Preview only: no live availability or booking has been confirmed.',illustration:'Illustrative photo',menu:'Menu',close:'Close menu',language:'Language',home:'Home',cars:'Our fleet',contact:'Contact',timezone:'Tangier local time'},
 es:{eyebrow:'ALQUILER DE COCHES EN TÁNGER',title:'Tu viaje empieza aquí.',benefit:'Recoge tu coche en el aeropuerto de Tánger o en tu hotel, en las fechas que prefieras.',search:'Ver disponibilidad',booking:'Tu reserva',hint:'Elige el lugar y las fechas.',place:'Lugar de recogida',airport:'Aeropuerto de Tánger',city:'Tánger Centro',hotel:'Hotel',departure:'Recogida',return:'Devolución',date:'Fecha',time:'Hora',delivery:'Entrega Aeropuerto / Hotel',terms:'Seguro y condiciones por confirmar',format:'Introduce una fecha válida dd/mm/aaaa y una hora de 24 horas.',invalid:'La devolución debe ser posterior a la recogida.',past:'Elige una recogida futura.',ready:'Búsqueda preparada. Solo vista previa: no se ha confirmado disponibilidad ni reserva.',illustration:'Foto ilustrativa',menu:'Menú',close:'Cerrar menú',language:'Idioma',home:'Inicio',cars:'Nuestra flota',contact:'Contacto',timezone:'Hora local de Tánger'}
};
// Keep date/time presentation independent of the browser's regional settings.
function parseDate(value:string):string|null{
 const match=/^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
 if(!match)return null;
 const [,day,month,year]=match;
 const date=new Date(Date.UTC(Number(year),Number(month)-1,Number(day)));
 if(Number(year)<1900||date.getUTCFullYear()!==Number(year)||date.getUTCMonth()!==Number(month)-1||date.getUTCDate()!==Number(day))return null;
 return year+'-'+month+'-'+day;
}
function validTime(value:string){return /^([01]\d|2[0-3]):[0-5]\d$/.test(value);}
function dateInput(value:string){const digits=value.replace(/\D/g,'').slice(0,8);return [digits.slice(0,2),digits.slice(2,4),digits.slice(4)].filter(Boolean).join('/');}
function timeInput(value:string){const digits=value.replace(/\D/g,'').slice(0,4);return [digits.slice(0,2),digits.slice(2)].filter(Boolean).join(':');}
type Language=keyof typeof copy;
const languages:Language[]=['en','ar','fr','es'];
const fullLanguageNames={fr:'Français',ar:'العربية',en:'English',es:'Español'};
const confirmation={fr:"L'agence vous appelle pour confirmer votre réservation.",en:'The agency will call you to confirm your reservation.',es:'La agencia te llamará para confirmar tu reserva.',ar:'ستتصل بك الوكالة لتأكيد حجزك.'};
const languageNames={fr:'FR',en:'EN',es:'ES',ar:'عربي'};
const details={
 fr:{titleA:'La route',titleB:'commence ici.',choose:'Choisir la date',placeholder:'jj/mm/aaaa',image:'SUV au bord de la mer à Tanger — illustration',destination:'Tanger, à votre rythme.',destinationSub:'Votre arrivée. Votre voiture. Votre liberté.',airport:'Aéroport',hotel:'Hôtel'},
 en:{titleA:'Your journey',titleB:'starts here.',choose:'Choose date',placeholder:'dd/mm/yyyy',image:'SUV by the sea in Tangier — illustration',destination:'Tangier, at your own pace.',destinationSub:'Your arrival. Your car. Your freedom.',airport:'Airport',hotel:'Hotel'},
 es:{titleA:'Tu viaje',titleB:'empieza aquí.',choose:'Elegir fecha',placeholder:'dd/mm/aaaa',image:'SUV junto al mar en Tánger — ilustración',destination:'Tánger, a tu ritmo.',destinationSub:'Tu llegada. Tu coche. Tu libertad.',airport:'Aeropuerto',hotel:'Hotel'},
 ar:{titleA:'من هنا',titleB:'تبدأ رحلتك.',choose:'اختيار التاريخ',placeholder:'dd/mm/yyyy',image:'سيارة على ساحل طنجة — صورة توضيحية',destination:'طنجة، على إيقاعك.',destinationSub:'وصولك. سيارتك. حريتك.',airport:'المطار',hotel:'الفندق'}
};
const calendarLocales={fr,ar,en:enUS,es};
function HeroDateField({label,value,onChange,language}:{label:string;value:string;onChange:(value:string)=>void;language:Language}){
 const [opened,setOpened]=useState(false);
 const button=useRef<HTMLButtonElement>(null);
 const iso=parseDate(value);
 const selected=iso?new Date(iso+'T12:00:00'):undefined;
 return <div className="bh-calendar-field" onBlur={event=>{if(!event.currentTarget.contains(event.relatedTarget as Node))setOpened(false);}} onKeyDown={event=>{if(event.key==='Escape'){setOpened(false);button.current?.focus();}}}>
  <label><span>{copy[language].date}</span><input aria-label={label} type="text" inputMode="numeric" placeholder={details[language].placeholder} maxLength={10} dir="ltr" required value={value} onInput={e=>onChange(dateInput(e.currentTarget.value))} onChange={e=>onChange(dateInput(e.target.value))}/></label>
  <button ref={button} className="bh-calendar-trigger" type="button" aria-label={details[language].choose+' : '+label} aria-expanded={opened} onClick={()=>setOpened(!opened)}><CalendarDays aria-hidden="true" size={17}/></button>
  {opened&&<div className="bh-calendar-popup" role="dialog" aria-label={label}>
   <DayPicker mode="single" locale={calendarLocales[language]} numerals="latn" dir={language==='ar'?'rtl':'ltr'} selected={selected} defaultMonth={selected} autoFocus onSelect={date=>{if(date){onChange(String(date.getDate()).padStart(2,'0')+'/'+String(date.getMonth()+1).padStart(2,'0')+'/'+date.getFullYear());setOpened(false);button.current?.focus();}}}/>
  </div>}
 </div>;
}

export default function PublicSite({initialLanguage='fr',initialVehicle=''}:{initialLanguage?:Language;initialVehicle?:string}){
 const [language,setLanguage]=useState<Language>(initialLanguage);
 const [languageOpen,setLanguageOpen]=useState(false);
 const languageRef=useRef<HTMLButtonElement>(null);
 const languageMenuRef=useRef<HTMLDivElement>(null);
 useEffect(()=>{
  if(!languageOpen)return;
  const outside=(event:PointerEvent)=>{if(!languageMenuRef.current?.contains(event.target as Node))setLanguageOpen(false);};
  const escape=(event:KeyboardEvent)=>{if(event.key==='Escape'){setLanguageOpen(false);languageRef.current?.focus();}};
  document.addEventListener('pointerdown',outside);document.addEventListener('keydown',escape);
  return()=>{document.removeEventListener('pointerdown',outside);document.removeEventListener('keydown',escape);};
 },[languageOpen]);
 const [selectedCar,setSelectedCar]=useState('');
 const [vehicleId,setVehicleId]=useState(initialVehicle);
 const [bookingOpen,setBookingOpen]=useState(false);
 const [open,setOpen]=useState(false),[status,setStatus]=useState<'idle'|'invalid'|'format'|'past'|'ready'>('idle');
 const [place,setPlace]=useState('airport'),[departure,setDeparture]=useState(''),[departureTime,setDepartureTime]=useState('10:00'),[returnDate,setReturnDate]=useState(''),[returnTime,setReturnTime]=useState('10:00');
 const bookingRef=useRef<HTMLFormElement>(null);
 useEffect(()=>{const form=bookingRef.current;if(!form)return;const resize=new ResizeObserver(()=>{form.closest('.nh-hero')?.setAttribute('style','--booking-half:'+form.getBoundingClientRect().height/2+'px');});resize.observe(form);return()=>resize.disconnect();},[vehicleId]);
 const locationRef=useRef<HTMLSelectElement>(null),menuRef=useRef<HTMLButtonElement>(null);
 const t=copy[language],d=details[language];
 useEffect(()=>{const selected=new URLSearchParams(window.location.search).get('lang');if(languages.includes(selected as Language))setLanguage(selected as Language);},[]);
 useEffect(()=>{function read(){const p=new URLSearchParams(window.location.search);const requestedLanguage=p.get('lang');if(languages.includes(requestedLanguage as Language))setLanguage(requestedLanguage as Language);setVehicleId(p.get('vehicle')??(/^\/(fr|ar|en|es)\/voitures\/(\d+)$/.exec(window.location.pathname)?.[2]??''));if(p.has('departure'))setDeparture(p.get('departure')??'');if(p.has('return'))setReturnDate(p.get('return')??'');if(p.has('departureTime'))setDepartureTime(p.get('departureTime')??'10:00');if(p.has('returnTime'))setReturnTime(p.get('returnTime')??'10:00');if(['airport','city','hotel','agency'].includes(p.get('place')??''))setPlace(p.get('place')!);setStatus('idle');}read();window.addEventListener('popstate',read);return()=>window.removeEventListener('popstate',read);},[]);
 function openCar(name:string,id:number){setBookingOpen(false);setSelectedCar(name);setVehicleId(String(id));setStatus('idle');const url=new URL(window.location.href);url.pathname='/'+language+'/voitures/'+id;url.searchParams.delete('vehicle');url.searchParams.set('lang',language);for(const [key,value] of Object.entries({departure,return:returnDate,departureTime,returnTime,place}))url.searchParams.set(key,value);url.hash='home';window.history.pushState(null,'',url);window.scrollTo(0,0);}
 function backToFleet(home=false){setVehicleId('');setStatus('idle');const url=new URL(window.location.href);url.pathname='/'+language;url.searchParams.delete('vehicle');for(const [key,value] of Object.entries({departure,return:returnDate,departureTime,returnTime,place}))url.searchParams.set(key,value);url.hash=home?'home':'fleet';window.history.pushState(null,'',url);requestAnimationFrame(()=>document.getElementById(home?'home':'fleet')?.scrollIntoView());}
 function changeLanguage(next:Language){setLanguage(next);setLanguageOpen(false);languageRef.current?.focus();const url=new URL(window.location.href);if(/^\/(fr|ar|en|es)(\/|$)/.test(url.pathname))url.pathname=url.pathname.replace(/^\/(fr|ar|en|es)/,'/'+next);url.searchParams.set('lang',next);window.location.assign(url);}
 function focusSearch(){setOpen(false);setBookingOpen(true);requestAnimationFrame(()=>{locationRef.current?.focus();locationRef.current?.scrollIntoView({block:'center',behavior:'auto'});});}
 function submit(event:FormEvent<HTMLFormElement>){event.preventDefault();const startDate=parseDate(departure),endDate=parseDate(returnDate);if(!startDate||!endDate||!validTime(departureTime)||!validTime(returnTime)){setStatus('format');return;}const start=startDate+'T'+departureTime,end=endDate+'T'+returnTime;if(end<=start){setStatus('invalid');return;}const parts=new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Casablanca',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date()).replace(' ','T');if(start<parts){setStatus('past');return;}try{sessionStorage.setItem('bensbih-website-draft',JSON.stringify({starts_local:start,ends_local:end,pickup:place,dropoff:place}));}catch{}window.location.assign('/'+language+'/'+(vehicleId?'demande/'+vehicleId:'voitures'));}
 return <div className="bh-page" lang={language} dir={language==='ar'?'rtl':'ltr'}>
 <a className="bh-skip" href="#reservation" onClick={e=>{e.preventDefault();focusSearch();}}>{t.search}</a>

 <main id="home" className="bh-main"><section className={vehicleId?'bh-hero bh-detail':'nh-hero'} aria-labelledby={vehicleId?undefined:'hero-title'}>
  {vehicleId?<VehicleDetail id={vehicleId} language={language} onBack={()=>backToFleet()} onLoaded={setSelectedCar} onReserve={focusSearch} onChoose={openCar}/>:<div className="nh-banner" data-wa-protect><picture className="nh-photo"><source media="(max-width: 767px)" srcSet="/brand-kit/tanger-banner-mobile.webp"/><img src="/brand-kit/tanger-banner-wide.webp" alt={d.image} width="1944" height="809" fetchPriority="high"/></picture><div className="nh-intro">
   <p className="nh-eyebrow"> {t.eyebrow}</p>
   <h1 id="hero-title"><span>{d.titleA}</span><span>{d.titleB}</span></h1>
   <p className="nh-description">{t.benefit}</p>
  </div>
  </div>}

  <form ref={bookingRef} hidden={Boolean(vehicleId&&!bookingOpen)} id="reservation" className={'bh-search '+(!vehicleId?'nh-booking':'')} noValidate onSubmit={submit} onChange={()=>setStatus('idle')}>
   <div className="bh-form-heading"><h2>{t.booking}</h2><span><CalendarDays aria-hidden="true" size={15}/>{t.timezone}</span></div>
   {vehicleId&&<div className="bd-pricing"><strong>{detailCopy[language].price}</strong><p>{detailCopy[language].priceNote}</p></div>}
   {!vehicleId&&selectedCar&&<SelectedCar language={language} name={selectedCar} onClear={()=>setSelectedCar('')}/>}
   <div className="bh-fields">
    <label className="bh-place"><span><MapPin aria-hidden="true" size={16}/>{t.place}</span><select ref={locationRef} required value={place} onChange={e=>setPlace(e.target.value)}><option value="agency">{{fr:'Agence — Branes, Tanger',en:'Agency — Branes, Tangier',es:'Agencia — Branes, Tánger',ar:'الوكالة — برانص، طنجة'}[language]}</option><option value="airport">{t.airport}</option><option value="city">{t.city}</option><option value="hotel">{t.hotel}</option></select></label>
    <TravelDates language={language} start={departure} end={returnDate} startTime={departureTime} endTime={returnTime} onChange={(s,e,st,et)=>{setDeparture(s);setReturnDate(e);setDepartureTime(st);setReturnTime(et);setStatus('idle');}}/>
    <button className="bh-search-submit" disabled={Boolean(vehicleId&&!selectedCar)} type="submit">{vehicleId?detailCopy[language].request:t.search}<ArrowRight aria-hidden="true" size={19}/></button>
   </div>
   {vehicleId&&<><div className="bh-delivery"><MapPin size={16} aria-hidden="true"/><span>{t.delivery}</span></div><div className="bh-trust"><ShieldCheck aria-hidden="true" size={15}/><span>{t.terms}</span></div></>}
   {status!=='idle'&&<p className={'bh-status '+(status==='ready'?'is-ready':'')} role={status==='ready'?'status':'alert'}>{vehicleId&&status==='ready'?detailCopy[language].ready:t[status]}</p>}
  </form>
 {!vehicleId&&<p className="nh-confirmation">{confirmation[language]}</p>}
 </section>{!vehicleId&&<><Fleet language={language} onChoose={openCar}/><WhyBenSbih language={language}/></>}</main>
 <FloatingWhatsApp language={language}/>
 </div>;
}
