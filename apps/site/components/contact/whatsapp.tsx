'use client';
import {useEffect,useRef,useState} from 'react';
import {whatsappHref,whatsappLabels,type ContactLanguage} from '../../lib/whatsapp';
import './whatsapp.css';
export function WhatsAppIcon(){return <svg width="23" height="23" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.52 3.48A11.86 11.86 0 0 0 12.05 0C5.44 0 .06 5.38.06 12c0 2.12.55 4.19 1.6 6.02L0 24l6.14-1.61a12 12 0 0 0 5.9 1.5h.01C18.66 23.89 24 18.51 24 11.9c0-3.2-1.24-6.21-3.48-8.42ZM12.05 21.87a9.9 9.9 0 0 1-5.04-1.38l-.36-.21-3.64.95.97-3.55-.24-.37a9.89 9.89 0 0 1-1.51-5.31c0-5.5 4.47-9.98 9.98-9.98a9.9 9.9 0 0 1 7.06 2.93 9.93 9.93 0 0 1 2.92 7.06c0 5.5-4.48 9.86-10.14 9.86Zm5.48-7.4c-.3-.15-1.77-.87-2.04-.97-.28-.1-.48-.15-.68.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.67-2.07-.17-.3-.02-.46.13-.61.13-.14.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.68-1.63-.93-2.23-.24-.59-.49-.51-.68-.52h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.5s1.07 2.91 1.22 3.11c.15.2 2.11 3.23 5.12 4.53.72.31 1.28.5 1.72.64.72.23 1.38.2 1.9.12.58-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.13-.27-.2-.57-.35Z"/></svg>}
export function CarWhatsApp({language,name}:{language:ContactLanguage;name:string}){
 return <a className="contact-wa-car" href={whatsappHref(language,name)} target="_blank" rel="noopener noreferrer" aria-label={whatsappLabels[language].car+' — '+name}><WhatsAppIcon/>{whatsappLabels[language].car}</a>;
}
export function FloatingWhatsApp({language}:{language:ContactLanguage}){
 const ref=useRef<HTMLAnchorElement>(null);
 const [position,setPosition]=useState<number|null>(null);
 useEffect(()=>{
  let frame=0;
  const update=()=>{
   frame=0;
   const link=ref.current;if(!link)return;
   const width=document.documentElement.clientWidth,height=window.innerHeight;
   if(width>767){setPosition(null);return;}
   const style=getComputedStyle(link);
   const right=parseFloat(style.right)||16,base=20+(parseFloat(style.getPropertyValue('--wa-safe-bottom'))||0);
   const left=width-right-56;
   // Whole forms are protected, not only their inputs; dialogs and every action are protected too.
   const obstacles=Array.from(document.querySelectorAll('form,button,a,input,select,textarea,figcaption,[role="button"],[role="dialog"],[data-wa-protect]'))
    .filter(el=>el!==link&&!link.contains(el))
    .map(el=>el.getBoundingClientRect())
    .filter(r=>r.width>0&&r.height>0&&r.bottom>0&&r.top<height&&r.right>left-8);
   let found:number|null=null;
   for(let bottom=base;bottom<=height-72;bottom+=8){
    const top=height-bottom-56;
    if(obstacles.every(r=>left+56+8<=r.left||left-8>=r.right||top+56+8<=r.top||top-8>=r.bottom)){found=bottom;break;}
   }
   // On a fully occupied viewport there is no safe floating slot. Inline contact remains available.
   setPosition(found);
  };
  const schedule=()=>{if(!frame)frame=requestAnimationFrame(update);};
  const resize=new ResizeObserver(schedule);resize.observe(document.body);
  const mutations=new MutationObserver(schedule);mutations.observe(document.body,{childList:true,subtree:true});
  window.addEventListener('scroll',schedule,true);window.addEventListener('resize',schedule);
  document.addEventListener('focusin',schedule);
  window.visualViewport?.addEventListener('resize',schedule);
  schedule();
  return()=>{cancelAnimationFrame(frame);resize.disconnect();mutations.disconnect();window.removeEventListener('scroll',schedule,true);window.removeEventListener('resize',schedule);document.removeEventListener('focusin',schedule);window.visualViewport?.removeEventListener('resize',schedule);};
 },[]);
 return <a ref={ref} className="contact-wa-floating" data-safe-position={position!==null} style={{bottom:position??20,visibility:position===null?'hidden':'visible'}} tabIndex={position===null?-1:0} aria-hidden={position===null} aria-label={whatsappLabels[language].general} title={whatsappLabels[language].general} href={whatsappHref(language)} target="_blank" rel="noopener noreferrer"><WhatsAppIcon/></a>;
}
