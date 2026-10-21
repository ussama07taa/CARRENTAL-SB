'use client';
import {useEffect, useState} from 'react';
import {ArrowUpRight, CarFront, ChevronLeft, ChevronRight, ImageOff, RefreshCw} from 'lucide-react';
import {api} from '../_lib/auth-client';

export type FleetPreview = {id:number;plate:string;make:string;model:string;year:number|null;current_odometer:number|null;cover_id:number|null};

export function VehiclePhoto({vehicle}:{vehicle:FleetPreview}) {
  const [url,setUrl]=useState(''),[failed,setFailed]=useState(false),[revision,setRevision]=useState(0);
  useEffect(()=>{
    const controller=new AbortController();let objectUrl='';setUrl('');setFailed(false);
    if(vehicle.cover_id){
      void (async()=>{try{
        const response=await fetch(`${api}/api/v1/vehicles/${vehicle.id}/photos/${vehicle.cover_id}/content`,{credentials:'include',cache:'no-store',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(15000)])});
        if(response.status===401){window.location.replace('/login');return;}
        if(!response.ok)throw new Error('Photo indisponible');
        const blob=await response.blob();
        if(!blob.type.startsWith('image/'))throw new Error('Format incorrect');
        if(!controller.signal.aborted){objectUrl=URL.createObjectURL(blob);setUrl(objectUrl);}
      }catch{if(!controller.signal.aborted)setFailed(true);}})();
    }
    return()=>{controller.abort();if(objectUrl)URL.revokeObjectURL(objectUrl);};
  },[vehicle.id,vehicle.cover_id,revision]);
  return <div className="fleet-spotlight-photo">
    {url&&!failed?<img src={url} alt={`${vehicle.make} ${vehicle.model} · ${vehicle.plate} — photo principale enregistrée`} onError={()=>setFailed(true)}/>:<div className="fleet-spotlight-placeholder">{failed?<ImageOff size={27}/>:<CarFront size={34}/>}<span>{failed?'Photo indisponible':vehicle.cover_id?'Chargement de la photo…':'Photo à ajouter'}</span>{failed&&<button type="button" onClick={()=>setRevision(value=>value+1)}><RefreshCw size={13}/> Réessayer la photo</button>}</div>}
  </div>;
}

export default function FleetSpotlight({vehicles,total,loading,error}:{vehicles:FleetPreview[];total:number|undefined;loading:boolean;error:boolean}) {
  const [index,setIndex]=useState(0);
  const position=Math.min(index,Math.max(0,vehicles.length-1));
  const vehicle=vehicles[position];
  return <section className="fleet-spotlight" aria-labelledby="fleet-spotlight-title">
    <header><div><span className="cp-kicker">VOS VÉHICULES</span><h2 id="fleet-spotlight-title">Aperçu du parc</h2></div><a href="/vehicles">Tout voir{total!==undefined&&<span> ({total})</span>}<ArrowUpRight size={14}/></a></header>
    {error?<p className="fleet-spotlight-empty" role="status">Le parc n’a pas pu être actualisé. Utilisez « Actualiser » en haut de la page.</p>:vehicle?<>
      <div className="fleet-spotlight-car"><VehiclePhoto key={`${vehicle.id}-${vehicle.cover_id}`} vehicle={vehicle}/><div className="fleet-spotlight-info" aria-live="polite" aria-atomic="true"><h3>{vehicle.make} <strong>{vehicle.model}</strong></h3><span className="fleet-spotlight-plate">{vehicle.plate}</span><p>{vehicle.year??'Année à renseigner'} · {vehicle.current_odometer===null?'Compteur à renseigner':new Intl.NumberFormat('fr-FR').format(vehicle.current_odometer)+' km'}</p></div></div>
      <footer><a href={`/vehicles?vehicle=${vehicle.id}`} aria-label={`Ouvrir le fichier ${vehicle.plate}`}>Ouvrir le fichier <ArrowUpRight size={15}/></a><div><span className="fleet-spotlight-position">{position+1} / {vehicles.length}</span><button disabled={position===0} aria-label="Voiture précédente" onClick={()=>setIndex(position-1)}><ChevronLeft size={18}/></button><button disabled={position>=vehicles.length-1} aria-label="Voiture suivante" onClick={()=>setIndex(position+1)}><ChevronRight size={18}/></button></div></footer>
    </>:<div className="fleet-spotlight-empty" role="status"><CarFront size={28}/><p>{loading?'Chargement du parc…':'Votre première voiture apparaîtra ici.'}</p>{!loading&&<a href="/vehicles?action=create">Ajouter un véhicule <ArrowUpRight size={14}/></a>}</div>}
  </section>;
}
