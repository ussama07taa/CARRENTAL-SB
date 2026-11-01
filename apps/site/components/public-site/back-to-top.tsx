"use client";
import {useEffect,useState} from 'react';
import {usePathname} from 'next/navigation';
import {ArrowUp} from 'lucide-react';
import type {ContactLanguage} from '../../lib/whatsapp';
import './back-to-top.css';
const labels={fr:'Retour en haut',en:'Back to top',es:'Volver arriba',ar:'الرجوع لفوق'};
export default function BackToTop({language}:{language:ContactLanguage}){const [visible,setVisible]=useState(false),path=usePathname();useEffect(()=>{const update=()=>setVisible(window.scrollY>400);update();window.addEventListener('scroll',update,{passive:true});return()=>window.removeEventListener('scroll',update)},[path]);return <button type="button" className={'back-top'+(visible?' is-visible':'')} aria-label={labels[language]} title={labels[language]} aria-hidden={!visible} tabIndex={visible?0:-1} onClick={()=>{window.scrollTo({top:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});const top=document.querySelector<HTMLAnchorElement>('.gh-brand');top?.focus({preventScroll:true});}}><ArrowUp size={22} strokeWidth={2}/></button>}
