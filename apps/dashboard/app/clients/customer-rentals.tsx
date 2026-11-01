 'use client';
import {useEffect,useState} from 'react';
import Link from 'next/link';
import {Plus,CalendarDays,ArrowUpRight,ChevronLeft,ChevronRight} from 'lucide-react';
import {api} from '../_lib/auth-client';
import {moroccoDateTimeFormat} from '../_lib/morocco-time';
type Rental={id:number;reference:string;status:string;vehicle:string;plate:string|null;starts_at:string;ends_at:string};
type Listing={data:Rental[];total:number;page:number;last_page:number};
const labels:Record<string,string>={demande:'Demande',temporaire:'Option temporaire',confirmee:'Confirmée',en_location:'En location',retournee:'Retournée',annulee:'Annulée'};
const date=(s:string)=>moroccoDateTimeFormat('fr-MA',{dateStyle:'medium',timeStyle:'short',timeZone:'Africa/Casablanca'}).format(new Date(s));
export default function CustomerRentals({customerId}:{customerId:number}){
 const [page,setPage]=useState(1),[reload,setReload]=useState(0),[data,setData]=useState<Listing|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true);
 useEffect(()=>{const abort=new AbortController();setLoading(true);setError('');setData(null);
 fetch(api+'/api/v1/customers/'+customerId+'/reservations?page='+page,{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:abort.signal}).then(async response=>{if(!response.ok)throw new Error('Les locations ne sont pas disponibles. Réessayez.');return response.json()}).then(value=>{if(!abort.signal.aborted)setData(value)}).catch(e=>{if(!abort.signal.aborted)setError(e.message)}).finally(()=>{if(!abort.signal.aborted)setLoading(false)});return()=>abort.abort();
 },[customerId,page,reload]);
 return <section className="customer-rentals" aria-label="Locations du client" aria-busy={loading}>
 <div className="client-panel-heading"><div><h3>Locations du client</h3><p>Demandes, locations en cours et précédentes · départs les plus récents en premier.</p></div><Link className="primary" href={"/reservations?create_for="+customerId}><Plus size={16}/> Nouvelle réservation</Link></div>
 {loading?<p role="status">Chargement des locations…</p>:error?<div role="alert"><p>{error}</p><button className="secondary" onClick={()=>setReload(n=>n+1)}>Réessayer</button></div>:data?.total===0?<div className="customer-rentals-empty"><CalendarDays size={28}/><h3>Aucune location enregistrée</h3><p>Les dossiers liés à ce client apparaîtront ici.</p></div>:data&&<>
 <div className="customer-rentals-scroll"><table><thead><tr><th>Dossier / voiture</th><th>Départ / retour prévu</th><th>État</th><th><span className="sr-only">Ouvrir</span></th></tr></thead><tbody>{data.data.map(row=><tr key={row.id} onClick={e=>{if(!(e.target as HTMLElement).closest('a'))e.currentTarget.querySelector('a')?.click();}}><td><Link href={'/reservations?dossier='+row.id}>{row.reference}</Link><strong>{row.vehicle||'Voiture non renseignée'}</strong><small>{row.plate}</small></td><td><span>{date(row.starts_at)}</span><small>Au {date(row.ends_at)}</small></td><td><span className={'rental-state rental-state-'+row.status}>{labels[row.status]??row.status}</span></td><td><Link className="client-icon" href={'/reservations?dossier='+row.id} aria-label={'Ouvrir '+row.reference} title="Ouvrir le dossier"><ArrowUpRight size={18}/></Link></td></tr>)}</tbody></table></div>
 <div className="customer-rentals-footer"><span>{data.total} dossier{data.total>1?'s':''} · page {data.page} / {data.last_page}</span><div><button className="client-icon" aria-label="Locations précédentes" disabled={page<=1} onClick={()=>setPage(n=>n-1)}><ChevronLeft size={18}/></button><button className="client-icon" aria-label="Locations suivantes" disabled={page>=data.last_page} onClick={()=>setPage(n=>n+1)}><ChevronRight size={18}/></button></div></div>
 </>}
 </section>
}
