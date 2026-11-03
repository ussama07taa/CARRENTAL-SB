'use client';
import {createContext,useContext,useEffect,useRef,useState,type ReactNode} from 'react';
export type Currency='MAD'|'EUR'|'USD';
type Rates={updated:number;EUR:number;USD:number};
const Context=createContext({currency:'MAD' as Currency,rates:null as Rates|null,busy:false,error:false,select:async(_c:Currency)=>false});
const valid=(r:Rates|null)=>!!r&&Number.isFinite(r.updated)&&Date.now()-r.updated<48*3600000&&r.updated<=Date.now()+300000&&[r.EUR,r.USD].every(n=>Number.isFinite(n)&&n>0);
export function CurrencyProvider({children,country}:{children:ReactNode;country?:string}){
 const [currency,setCurrency]=useState<Currency>('MAD'),[rates,setRates]=useState<Rates|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState(false);const lock=useRef(false);
 async function select(next:Currency,persist=true){
  if(lock.current)return false;setError(false);
  if(next==='MAD'){setCurrency(next);try{if(persist)localStorage.setItem('ben-sbih-currency',next);}catch{}return true;}
  lock.current=true;setBusy(true);
  try{
   let latest=rates;
   if(!valid(latest)){
    try{latest=JSON.parse(localStorage.getItem('ben-sbih-display-rates')??'null');}catch{}
    if(!valid(latest)){
     const response=await fetch('https://open.er-api.com/v6/latest/MAD',{signal:AbortSignal.timeout(8000)});
     if(!response.ok)throw Error();const data=await response.json();
     latest={updated:Number(data.time_last_update_unix)*1000,EUR:data.rates?.EUR,USD:data.rates?.USD};
     if(data.result!=='success'||data.base_code!=='MAD'||!valid(latest))throw Error();
     try{localStorage.setItem('ben-sbih-display-rates',JSON.stringify(latest));}catch{}
    }
   }
   setRates(latest);setCurrency(next);try{if(persist)localStorage.setItem('ben-sbih-currency',next);}catch{}return true;
  }catch{setError(true);setCurrency('MAD');return false;}finally{lock.current=false;setBusy(false);}
 }
 useEffect(()=>{let saved:string|null=null;try{saved=localStorage.getItem('ben-sbih-currency');}catch{}if(saved==='MAD'||saved==='EUR'||saved==='USD'){void select(saved,false);return;}
 // A browser region is a preference hint, not proof of physical location.
 let region=country?.toUpperCase();if(!region){for(const tag of navigator.languages){try{const candidate=new Intl.Locale(tag).region;if(candidate){region=candidate;break;}}catch{}}}
 const euro=['AT','BE','HR','CY','EE','FI','FR','DE','GR','IE','IT','LV','LT','LU','MT','NL','PT','SK','SI','ES','BG'];
 const initial:Currency=region==='US'?'USD':region&&euro.includes(region)?'EUR':'MAD';void select(initial,false);
 },[country]);
 // Expired indicative rates never remain presented as a current conversion.
 useEffect(()=>{if(!rates)return;const timer=setInterval(()=>{if(!valid(rates)){setCurrency('MAD');setRates(null);}},60000);return()=>clearInterval(timer);},[rates]);
 return <Context.Provider value={{currency,rates,busy,error,select}}>{children}</Context.Provider>;
}
export function useDisplayCurrency(){return useContext(Context);}
export const currencyNotice={fr:'Estimation · prix final et paiement en MAD.',en:'Estimate · final price and payment in MAD.',es:'Estimación · precio final y pago en MAD.',ar:'تحويل تقريبي · الثمن النهائي والأداء بالدرهم.'};

export function CurrencyAttribution(){const {currency,rates}=useDisplayCurrency();if(currency==='MAD'||!rates)return null;return <a className="sf-currency-source" href="https://www.exchangerate-api.com" target="_blank" rel="noopener noreferrer">Rates By Exchange Rate API</a>;}
