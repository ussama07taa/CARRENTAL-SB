'use client';
import {useId,useRef,useState,useEffect} from 'react';
import {createPortal} from 'react-dom';
import {fieldHelp} from './field-help-text';
export default function FieldHelp({field,label,hover=false}:{field:string;label?:string;hover?:boolean}){
 const id=useId(),button=useRef<HTMLButtonElement>(null),panel=useRef<HTMLDivElement>(null);
 const [host,setHost]=useState<HTMLElement|null>(null),[opened,setOpened]=useState(false);
 const hideTimer=useRef<ReturnType<typeof setTimeout>|null>(null),pinned=useRef(false);
 const text=fieldHelp[field]??'هنا دخل المعلومة المطلوبة حسب الوثيقة أو الحالة الحقيقية. الخانة اللي فيها * ضرورية؛ إلا كانت اختيارية وما عندكش المعلومة، خليها خاوية وكملها من بعد.';
 useEffect(()=>{setHost(button.current?.closest('dialog')??document.body);},[]);
 useEffect(()=>()=>{if(hideTimer.current)clearTimeout(hideTimer.current);},[]);
 function keep(){if(hideTimer.current)clearTimeout(hideTimer.current);}
 function leave(){if(!hover||pinned.current)return;hideTimer.current=setTimeout(()=>{panel.current?.hidePopover();},180);}
 function show(toggle=true){keep();const p=panel.current,b=button.current;if(!p||!b)return;if(p.matches(':popover-open')){if(toggle)p.hidePopover();return;}const box=b.getBoundingClientRect();const width=Math.min(340,window.innerWidth-32);p.style.width=width+'px';p.style.left=Math.max(16,Math.min(box.left,window.innerWidth-width-16))+'px';p.style.top=Math.max(16,Math.min(box.bottom+8,window.innerHeight-220))+'px';p.showPopover();p.style.top=Math.max(16,Math.min(box.bottom+8,window.innerHeight-p.offsetHeight-16))+'px';}
 return <><button ref={button} type="button" className="field-info" aria-label={'Aide : '+(label??field)} aria-expanded={opened} aria-controls={id} onMouseEnter={hover?()=>show(false):undefined} onMouseLeave={leave} onClick={e=>{e.preventDefault();e.stopPropagation();if(hover){const close=pinned.current&&panel.current?.matches(':popover-open');pinned.current=!close;show(!!close);}else show();}}><span aria-hidden="true">i</span></button>{host&&createPortal(<div ref={panel} id={id} popover="auto" className="field-help-popover" onMouseEnter={keep} onMouseLeave={leave} onToggle={e=>{setOpened(e.newState==='open');if(e.newState!=='open')pinned.current=false;}} onClick={e=>e.stopPropagation()}><header><strong lang="fr">{label??'Aide à la saisie'}</strong><button type="button" aria-label="Fermer l’aide" onClick={e=>{e.preventDefault();panel.current?.hidePopover();button.current?.focus();}}>×</button></header><p dir="rtl" lang="ar-MA">{text}</p></div>,host)}</>;
}
