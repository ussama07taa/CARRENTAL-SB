import type { Metadata } from 'next';
import { seoCopy } from './seo-copy';
export const siteLanguages = ['fr','ar','en','es'] as const;
export type SiteLanguage = typeof siteLanguages[number];
export const publicPaths = ['', 'voitures', 'contact', 'a-propos', 'faq', 'conditions', 'confidentialite'] as const;
// Intentionally closed until an explicitly approved deployment change.
export const closedRobots = { index:false, follow:false } as const;
export function siteOrigin() {
 const configured=process.env.SITE_PUBLIC_URL;
 const url=new URL(configured || 'http://localhost:3106');
 if(!['http:','https:'].includes(url.protocol)||url.username||url.password||url.pathname!=='/'||url.search||url.hash) throw new Error('SITE_PUBLIC_URL must be an http(s) origin without credentials, path or query');
 return url.origin;
}
export function siteUrl(language:SiteLanguage,path='') { return `${siteOrigin()}/${language}${path?`/${path}`:''}`; }
export function languageAlternates(path='') {
 return {...Object.fromEntries(siteLanguages.map(lang=>[lang,siteUrl(lang,path)])), 'x-default':siteUrl('fr',path)};
}
export function pageMetadata(language:string,key:keyof typeof seoCopy.fr,path:string=key,car?:string):Metadata {
 const lang:SiteLanguage=siteLanguages.includes(language as SiteLanguage)?language as SiteLanguage:'fr';
 const pair=seoCopy[lang][key];
 const title=pair[0].replace('{car}',car??'')+' | BEN SBIH';
 const description=pair[1].replace('{car}',car??'');
 const privatePage=['demande','confirmation','suivi'].includes(key);
 return {title,description,robots:closedRobots,
  ...(!privatePage?{alternates:{canonical:siteUrl(lang,path),languages:languageAlternates(path)}}:{}),
  openGraph:{title,description,siteName:'BEN SBIH Rental Car',type:'website',locale:({fr:'fr_MA',ar:'ar_MA',en:'en_GB',es:'es_ES'})[lang],...(!privatePage?{url:siteUrl(lang,path)}:{})},
  twitter:{card:'summary',title,description},
 };
}
export type SeoVehicle={id:number;make:string;model:string;year?:number};
export async function publishedVehicles():Promise<SeoVehicle[]> {
 try {
  const response=await fetch(`${process.env.SITE_API_URL??'http://127.0.0.1:8000'}/api/v1/public/vehicles`,{cache:'no-store',signal:AbortSignal.timeout(5000),headers:{Accept:'application/json'}});
  if(!response.ok)return [];
  const body=await response.json();
  if(!Array.isArray(body.data))return [];
  return body.data.filter((v:SeoVehicle)=>Number.isSafeInteger(v.id)&&v.id>0&&typeof v.make==='string'&&typeof v.model==='string');
 }catch{return [];}
}
