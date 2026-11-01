'use client';
import {useEffect,useState} from 'react';
import {Building2} from 'lucide-react';
import {api} from '../_lib/auth-client';
export default function AgencyBrand(){
 const [brand,setBrand]=useState<{name:string|null;custom:boolean;has_logo:boolean;logo_revision?:string}|null>(null);
 useEffect(()=>{const ctl=new AbortController();const refresh=()=>{fetch(api+'/api/v1/agency-branding',{signal:ctl.signal,cache:'no-store'}).then(r=>r.ok?r.json():null).then(setBrand).catch(()=>{});};refresh();window.addEventListener('agency-profile-updated',refresh);return()=>{ctl.abort();window.removeEventListener('agency-profile-updated',refresh);};},[]);
 if(brand?.custom)return brand.has_logo?<img className="agency-logo" src={api+'/api/v1/agency-branding/logo?v='+(brand.logo_revision??'')} alt={brand.name??'Votre agence'} width={218} height={48} style={{objectFit:'contain'}}/>:<strong style={{display:'flex',alignItems:'center',gap:10}}><Building2 size={24}/>{brand.name}</strong>;
 return <strong style={{display:'flex',alignItems:'center',gap:10}}><Building2 size={24}/>{brand?.name??'Espace agence'}</strong>;
}
