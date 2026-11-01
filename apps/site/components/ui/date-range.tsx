'use client';
import {useEffect,useState} from 'react';
import {DayPicker,type DateRange} from 'react-day-picker';
import 'react-day-picker/style.css';
import {fr,ar,enGB,es} from 'react-day-picker/locale';
export function DateRangePicker({value,onChange,language="fr"}:{language?:"fr"|"ar"|"en"|"es";value:DateRange|undefined;onChange:(value:DateRange|undefined)=>void}){const [ready,setReady]=useState(false);useEffect(()=>setReady(true),[]);if(!ready)return <div style={{minHeight:300}} aria-busy="true"/>;return <DayPicker locale={{fr,ar,en:enGB,es}[language]} dir={language==="ar"?"rtl":"ltr"} mode="range" selected={value} onSelect={onChange} numberOfMonths={1} showOutsideDays navLayout="after"/>}
