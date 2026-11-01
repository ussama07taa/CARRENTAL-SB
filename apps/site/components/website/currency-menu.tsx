'use client';
import {useEffect,useRef,useState} from 'react';
import {Check,ChevronDown} from 'lucide-react';
import HeaderFlag from './header-flag';
import {useDisplayCurrency,currencyNotice,type Currency} from './display-currency';
const text={fr:{title:'DEVISE',MAD:'Dirham marocain',USD:'Dollar américain',EUR:'Euro',error:'Conversion indisponible. Prix affichés en MAD.',loading:'Actualisation…'},en:{title:'CURRENCY',MAD:'Moroccan Dirham',USD:'US Dollar',EUR:'Euro',error:'Conversion unavailable. Prices shown in MAD.',loading:'Updating…'},es:{title:'MONEDA',MAD:'Dírham marroquí',USD:'Dólar estadounidense',EUR:'Euro',error:'Conversión no disponible. Precios en MAD.',loading:'Actualizando…'},ar:{title:'العملة',MAD:'الدرهم المغربي',USD:'الدولار الأمريكي',EUR:'اليورو',error:'التحويل غير متاح. الأثمنة بالدرهم.',loading:'جارٍ التحديث…'}};
export default function CurrencyMenu({language}:{language:keyof typeof text}){
 const [open,setOpen]=useState(false);const root=useRef<HTMLDivElement>(null),trigger=useRef<HTMLButtonElement>(null);const c=useDisplayCurrency(),t=text[language];
 useEffect(()=>{if(!open)return;const outside=(e:PointerEvent)=>{if(!root.current?.contains(e.target as Node))setOpen(false);};document.addEventListener('pointerdown',outside);return()=>document.removeEventListener('pointerdown',outside);},[open]);
 return <div ref={root} className="nh-currency" onBlur={e=>{if(!e.currentTarget.contains(e.relatedTarget as Node))setOpen(false);}} onKeyDown={e=>{if(e.key==='Escape'){e.stopPropagation();setOpen(false);trigger.current?.focus();}}}>
 <button ref={trigger} className="nh-currency-trigger" type="button" aria-label={`${t.title} : ${c.currency}`} aria-expanded={open} aria-controls="home-currencies" onClick={()=>setOpen(!open)}><HeaderFlag code={c.currency}/><span>{c.currency}</span><ChevronDown size={13}/></button>
 {open&&<div className="nh-choice-list" id="home-currencies" role="group" aria-label={t.title}><p className="nh-choice-title">{t.title}</p>{(['USD','MAD','EUR'] as Currency[]).map(code=><button type="button" className="nh-choice" aria-pressed={c.currency===code} aria-disabled={c.busy} key={code} onClick={async()=>{if(await c.select(code)){setOpen(false);trigger.current?.focus();}}}><HeaderFlag code={code}/><span><strong>{code}</strong><small>{t[code]}</small></span>{c.currency===code&&<Check size={15} aria-hidden="true"/>}</button>)}<p className="nh-currency-note">{currencyNotice[language]}</p>{(c.error||c.busy)&&<p className="nh-currency-note" role="status">{c.busy?t.loading:t.error}</p>}</div>}
 </div>;
}
