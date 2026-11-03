'use client';
import {useState} from 'react';
import './vehicle-gallery.css';
import {useDisplayCurrency,currencyNotice} from './display-currency';
export type PublicPhoto={slot:string;image:string};
export type CatalogueLanguage='fr'|'en'|'es'|'ar';
export const bookLabel={fr:'Réserver',en:'Book',es:'Reservar',ar:'احجز'};
const labels={
 fr:['Photo principale','Vue avant','Vue arrière','Habitacle','Coffre','Détail 1 / côté','Détail 2','Détail 3'],
 en:['Main photo','Front','Rear','Interior','Boot','Detail 1 / side','Detail 2','Detail 3'],
 es:['Foto principal','Frontal','Trasera','Interior','Maletero','Detalle 1 / lateral','Detalle 2','Detalle 3'],
 ar:['الصورة الرئيسية','الأمام','الخلف','المقصورة','صندوق الأمتعة','تفصيل 1 / الجانب','تفصيل 2','تفصيل 3']
};
const slots=['cover','front','rear','interior','boot','detail_1','detail_2','detail_3'];
export function DailyPrice({value,language}:{value:number|null|undefined;language:CatalogueLanguage}) {
 const {currency,rates}=useDisplayCurrency();
 if(value==null)return null;
 const converted=currency!=='MAD'&&rates;const amount=value/100*(converted?rates[currency as 'EUR'|'USD']:1);
 const formatted=converted?new Intl.NumberFormat('en-US',{style:'currency',currency,currencyDisplay:'narrowSymbol',minimumFractionDigits:0,maximumFractionDigits:0}).format(Math.trunc(amount)):new Intl.NumberFormat('en-US',{minimumFractionDigits:0,maximumFractionDigits:0}).format(Math.trunc(amount))+' DH';
 return <div className="public-daily-price" data-daily-price><strong><bdi dir="ltr">{formatted}</bdi></strong> <span>{({fr:'/ jour',en:'/ day',es:'/ día',ar:'/ يوم'})[language]}</span>{converted&&<small className="public-currency-note">{currencyNotice[language]} <bdi>{new Intl.NumberFormat(language).format(value/100)} MAD</bdi></small>}</div>;
}
export default function VehicleGallery({photos,name,language}:{photos:PublicPhoto[];name:string;language:CatalogueLanguage}) {
 const [active,setActive]=useState(0);const photo=photos[active]??photos[0];
 if(!photo)return null;
 const label=(slot:string)=>labels[language][slots.indexOf(slot)]??slot;
 return <div className="public-gallery"><div className="public-gallery-main"><img src={photo.image} alt={`${name} — ${label(photo.slot)}`} width="960" height="640"/><span>{Math.min(active+1,photos.length)} / {photos.length}</span></div>
 {photos.length>1&&<div className="public-gallery-thumbnails">{photos.map((p,i)=><button type="button" key={p.slot} aria-label={label(p.slot)} aria-pressed={p===photo} onClick={()=>setActive(i)}><img src={p.image} alt="" width="120" height="80" loading="lazy"/></button>)}</div>}</div>;
}
