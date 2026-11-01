'use client';
import {useEffect,useRef,useState} from 'react';
import {ArrowUpRight,ChevronDown,Globe2,Menu,X,ArrowRight} from 'lucide-react';
import '@fontsource-variable/manrope';
import '@fontsource-variable/noto-sans-arabic';
import './header.css';
export type HeaderLanguage='fr'|'en'|'es'|'ar';
export type HeaderDestination='home'|'cars'|'contact'|'request';
const words={
 fr:{home:'Accueil',cars:'Nos voitures',contact:'Contact',request:'Réserver une voiture',menu:'Ouvrir le menu',close:'Fermer le menu',language:'Choisir la langue',nav:'Navigation principale'},
 en:{home:'Home',cars:'Our cars',contact:'Contact',request:'Book a car',menu:'Open menu',close:'Close menu',language:'Choose language',nav:'Main navigation'},
 es:{home:'Inicio',cars:'Nuestros coches',contact:'Contacto',request:'Reservar un coche',menu:'Abrir menú',close:'Cerrar menú',language:'Elegir idioma',nav:'Navegación principal'},
 ar:{home:'الرئيسية',cars:'سياراتنا',contact:'تواصل معنا',request:'احجز سيارة',menu:'فتح القائمة',close:'إغلاق القائمة',language:'اختيار اللغة',nav:'التنقل الرئيسي'},
};
export default function SiteHeader({language='fr',active='home',onLanguage,onNavigate}:{language?:HeaderLanguage;active?:HeaderDestination;onLanguage:(language:HeaderLanguage)=>void;onNavigate:(destination:HeaderDestination)=>void}){
 const [open,setOpen]=useState(false),toggle=useRef<HTMLButtonElement>(null),root=useRef<HTMLElement>(null);
 const t=words[language];
 useEffect(()=>{if(!open)return;const key=(e:KeyboardEvent)=>{if(e.key==='Escape'){setOpen(false);toggle.current?.focus();}};const outside=(e:PointerEvent)=>{if(root.current&&!root.current.contains(e.target as Node))setOpen(false);};document.addEventListener('keydown',key);document.addEventListener('pointerdown',outside);return()=>{document.removeEventListener('keydown',key);document.removeEventListener('pointerdown',outside);}},[open]);
 function go(destination:HeaderDestination){setOpen(false);onNavigate(destination);if(open)toggle.current?.focus();}
 return <header className="bs-header" ref={root} dir={language==='ar'?'rtl':'ltr'} lang={language}>
  <div className="bs-header-inner">
   <a href="#home" className="bs-brand" aria-label="BEN SBIH Rental Car" onClick={e=>{e.preventDefault();go('home');}}><img src="/branding/ben-sbih-rental-car.svg" alt="BEN SBIH RENTAL CAR" width="228" height="46"/></a>
   <nav className="bs-desktop-nav" aria-label={t.nav}>{(['home','cars','contact'] as const).map(key=><a href={'#'+key} key={key} aria-current={active===key?'page':undefined} onClick={e=>{e.preventDefault();go(key);}}>{t[key]}</a>)}</nav>
   <div className="bs-header-tools">
    <div className="bs-language"><Globe2 size={17} aria-hidden="true"/><select aria-label={t.language} value={language} onChange={e=>onLanguage(e.target.value as HeaderLanguage)}><option value="fr">FR</option><option value="en">EN</option><option value="es">ES</option><option value="ar">عربي</option></select><ChevronDown size={12} aria-hidden="true"/></div>
    <a className="bs-book" href="#request" onClick={e=>{e.preventDefault();go('request');}}>{t.request}<ArrowUpRight size={18} aria-hidden="true"/></a>
    <button ref={toggle} className="bs-menu-toggle" aria-label={open?t.close:t.menu} aria-expanded={open} aria-controls="bs-mobile-navigation" onClick={()=>setOpen(!open)}>{open?<X size={23}/>:<Menu size={23}/>}</button>
   </div>
  </div>
  <div className="bs-mobile-menu" id="bs-mobile-navigation" hidden={!open}><nav aria-label={t.nav}>{(['home','cars','contact'] as const).map((key,i)=><a href={'#'+key} key={key} aria-current={active===key?'page':undefined} onClick={e=>{e.preventDefault();go(key);}}><span className="bs-nav-number">0{i+1}</span>{t[key]}<ArrowUpRight size={18}/></a>)}</nav><a className="bs-book" href="#request" onClick={e=>{e.preventDefault();go('request');}}>{t.request}<ArrowRight size={18}/></a></div>
 </header>;
}
