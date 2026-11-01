'use client';
import {useEffect,useState} from 'react';
import {ArrowUpRight,Users,Settings2,Fuel,CarFront,ChevronDown} from 'lucide-react';
import './fleet.css';
import {DailyPrice,bookLabel} from '../website/vehicle-gallery';
type Language='fr'|'en'|'es'|'ar';
type Vehicle={id:number;make:string;model:string;year:number;fuel:string;transmission:string;seats:number;doors:number;image:string|null;daily_centimes:number|null};
const text={
fr:{eyebrow:'LE CHOIX VOUS APPARTIENT',title:'Une voiture pour votre séjour.',intro:'Comparez les modèles et leurs caractéristiques. Les disponibilités se vérifient ensuite avec vos dates.',demo:'Collection de démonstration · photos et informations à valider avant publication.',details:'Voir les caractéristiques',choose:'Découvrir cette voiture',selected:'Modèle choisi',clear:'Retirer',seats:'places',doors:'portes',year:'Année',auto:'Automatique',manual:'Manuelle',petrol:'Essence',diesel:'Diesel',hybrid:'Hybride',electric:'Électrique',loading:'Chargement des voitures…',error:'Les voitures ne sont pas disponibles pour le moment.',retry:'Réessayer',empty:'Aucune voiture publiée pour le moment.',photo:'Photo à venir',all:'Tous les modèles'},
en:{eyebrow:'THE CHOICE IS YOURS',title:'A car for your stay.',intro:'Compare models and their features. Availability is checked next using your travel dates.',demo:'Demo collection · photos and information must be verified before publication.',details:'View specifications',choose:'Explore this car',selected:'Selected model',clear:'Remove',seats:'seats',doors:'doors',year:'Year',auto:'Automatic',manual:'Manual',petrol:'Petrol',diesel:'Diesel',hybrid:'Hybrid',electric:'Electric',loading:'Loading cars…',error:'Cars cannot be loaded right now.',retry:'Try again',empty:'No cars published yet.',photo:'Photo coming soon',all:'All models'},
es:{eyebrow:'TÚ ELIGES',title:'Un coche para tu estancia.',intro:'Compara los modelos y sus características. Después comprobaremos la disponibilidad para tus fechas.',demo:'Colección de demostración · fotos e información pendientes de validación.',details:'Ver características',choose:'Ver este coche',selected:'Modelo elegido',clear:'Quitar',seats:'plazas',doors:'puertas',year:'Año',auto:'Automático',manual:'Manual',petrol:'Gasolina',diesel:'Diésel',hybrid:'Híbrido',electric:'Eléctrico',loading:'Cargando coches…',error:'No se pueden cargar los coches ahora.',retry:'Reintentar',empty:'Todavía no hay coches publicados.',photo:'Foto próximamente',all:'Todos los modelos'},
ar:{eyebrow:'الاختيار لك',title:'سيارة تناسب إقامتك.',intro:'قارن بين الموديلات ومواصفاتها. التحقق من التوفر يتم بعد تحديد مواعيد رحلتك.',demo:'مجموعة تجريبية · الصور والمعلومات تحتاج إلى تأكيد قبل النشر.',details:'عرض المواصفات',choose:'اكتشف هذه السيارة',selected:'الموديل المختار',clear:'إزالة',seats:'مقاعد',doors:'أبواب',year:'السنة',auto:'أوتوماتيكية',manual:'يدوية',petrol:'بنزين',diesel:'ديزل',hybrid:'هجينة',electric:'كهربائية',loading:'جارٍ تحميل السيارات…',error:'تعذر تحميل السيارات حالياً.',retry:'إعادة المحاولة',empty:'لا توجد سيارات منشورة حالياً.',photo:'الصورة قريباً',all:'جميع الموديلات'}
};
export function SelectedCar({language,name,onClear}:{language:Language;name:string;onClear:()=>void}){
const t=text[language];return <div className="bf-selection" role="status"><CarFront size={19} aria-hidden="true"/><span>{t.selected}: <strong dir="auto">{name}</strong></span><button type="button" onClick={onClear}>{t.clear}</button></div>;
}
export default function Fleet({language,onChoose,vehicles}:{language:Language;onChoose:(name:string,id:number)=>void;vehicles?:Vehicle[]}){
const t=text[language];const [cars,setCars]=useState<Vehicle[]>([]),[state,setState]=useState('loading'),[demo,setDemo]=useState(false),[attempt,setAttempt]=useState(0);
useEffect(()=>{if(vehicles){setCars(vehicles);setState('ready');return;}const controller=new AbortController();setState('loading');fetch('/api/public/vehicles',{signal:controller.signal,cache:'no-store'}).then(r=>{if(!r.ok)throw Error();return r.json();}).then(data=>{if(!Array.isArray(data.data))throw Error();setCars(data.data);setDemo(data.demo===true);setState('ready');}).catch(e=>{if(e.name!=='AbortError')setState('error');});return ()=>controller.abort();},[attempt,vehicles]);
return <section className="bf-section" id="fleet" aria-labelledby="fleet-title">
<div className="bf-heading"><div><p className="bf-eyebrow">{t.eyebrow}</p><h2 id="fleet-title">{t.title}</h2></div><p className="bf-intro">{t.intro}</p></div>

{state==='loading'&&<p role="status" className="bf-message">{t.loading}</p>}
{state==='error'&&<div role="alert" className="bf-message">{t.error}<button type="button" onClick={()=>setAttempt(x=>x+1)}>{t.retry}</button></div>}
{state==='ready'&&cars.length===0&&<p className="bf-message">{t.empty}</p>}
<div className="bf-grid">{state==='ready'&&cars.map(car=><article className="bf-card" key={car.id}>
<div className="bf-visual"><button type="button" aria-label={t.choose} onClick={()=>onChoose(car.make+' '+car.model,car.id)}>{car.image?<img src={car.image} alt={car.make+' '+car.model} loading="lazy" width="640" height="420"/>:<span>{t.photo}</span>}</button></div>
<div className="bf-body"><p className="bf-make">{car.make}</p><h3>{car.model}</h3>
<ul className="bf-specs"><li><Settings2 size={16} aria-hidden="true"/>{car.transmission==='automatic'?t.auto:t.manual}</li><li><Users size={16} aria-hidden="true"/>{car.seats} {t.seats}</li><li><Fuel size={16} aria-hidden="true"/>{({petrol:t.petrol,diesel:t.diesel,hybrid:t.hybrid,electric:t.electric} as Record<string,string>)[car.fuel]??car.fuel}</li></ul>
<details className="bf-details"><summary>{t.details}<ChevronDown size={16} aria-hidden="true"/></summary><dl><div><dt>{t.year}</dt><dd>{car.year}</dd></div><div><dt>{t.doors}</dt><dd>{car.doors}</dd></div></dl></details>
<DailyPrice value={car.daily_centimes} language={language}/><a className="bf-choose" href={`/${language}/demande/${car.id}`}>{bookLabel[language]}<ArrowUpRight size={19} aria-hidden="true"/></a>
</div></article>)}</div>
</section>;
}
