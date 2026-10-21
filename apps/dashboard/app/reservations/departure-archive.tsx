'use client';

import {useEffect, useState} from 'react';
import {FileText,Image as ImageIcon,Video,ArrowUpRight,RefreshCw} from 'lucide-react';
import {api} from '../_lib/auth-client';
import DocumentViewer from '../vehicles/document-viewer';
import DepartureContract from './departure-contract';
import type {CustomerDocument} from '../clients/document-upload';
import './departure-archive.css';

type Attachment={id:number;kind:'original'|'signed';verified:boolean};
type Version={id:number;version:number;reference:string;files:Attachment[]};
type Inspection={vehicle:{id:number};latest:{id:number;kilometers:number;fuel_eighths:number;photos:{id:number;caption:string|null;mime:string}[]}|null};
type VehicleFile={id:number;kind:string;version:number;has_file:boolean};
type View={document:{id:number;kind:string;version:number};customerId?:number;vehicleId?:number;reservationId?:number;contractFile?:boolean};
const labels:Record<string,string>={identity_front:'CIN / passeport · recto',identity_back:'CIN / passeport · verso',licence_front:'Permis · recto',licence_back:'Permis · verso',registration:'Carte grise',insurance:'Assurance',inspection:'Visite technique',circulation:'Feuille de circulation',tax:'Vignette'};

function MediaPreview({id,file}:{id:number;file:{id:number;mime:string}}){
  const [src,setSrc]=useState('');
  useEffect(()=>{
    if(!file.mime.startsWith('image/'))return;
    const controller=new AbortController();let url='';
    void fetch(`${api}/api/v1/reservations/${id}/departure/inspection/photos/${file.id}/content`,{credentials:'include',cache:'no-store',signal:controller.signal})
      .then(async response=>{if(!response.ok)return;const blob=await response.blob();if(controller.signal.aborted||!['image/jpeg','image/png','image/webp'].includes(blob.type.split(';')[0]))return;url=URL.createObjectURL(blob);setSrc(url);}).catch(()=>{});
    return()=>{controller.abort();if(url)URL.revokeObjectURL(url);};
  },[id,file.id,file.mime]);
  return <span className="da-preview">{src?<img src={src} alt="" loading="lazy" onError={()=>setSrc('')}/>:file.mime.startsWith('video/')?<Video size={28}/>:<ImageIcon size={28}/>}</span>;
}

function useRead<T>(path:string|null,attempt:number){
  const [data,setData]=useState<T|null>(null),[error,setError]=useState('');
  useEffect(()=>{
    setData(null);setError('');if(!path)return;
    const controller=new AbortController();
    void (async()=>{try{
      const response=await fetch(`${api}/api/v1/${path}`,{credentials:'include',cache:'no-store',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(15000)])});
      if(!response.ok)throw Error(response.status===403?'Votre accès ne permet pas de consulter ces documents.':response.status===401?'Votre session a expiré. Reconnectez-vous.':'Documents indisponibles. Réessayez.');
      const body=await response.json();if(!controller.signal.aborted)setData(body.data);
    }catch(e){if(!controller.signal.aborted)setError((e as Error).message);}})();
    return()=>controller.abort();
  },[path,attempt]);
  return {data,error};
}

export default function DepartureArchive({id,customerId,files,canReadCustomer,onSaved,onBusyChange,onDirtyChange}:{id:number;customerId:number;files:CustomerDocument[];canReadCustomer:boolean;onSaved:()=>void;onBusyChange:(busy:boolean)=>void;onDirtyChange:(dirty:boolean)=>void}){
  const [attempt,setAttempt]=useState(0),[view,setView]=useState<View|null>(null),[vehicleOpen,setVehicleOpen]=useState(false);
  const [signing,setSigning]=useState(false),[signingBusy,setSigningBusy]=useState(false),[signingDirty,setSigningDirty]=useState(false);
  useEffect(()=>{onDirtyChange(signing);return()=>onDirtyChange(false);},[signing,onDirtyChange]);
  const contracts=useRead<{can_sign_rectification?:boolean;tablet_signing_enabled?:boolean;versions:Version[]}>(`reservations/${id}/departure/contract`,attempt);
  const inspection=useRead<Inspection>(`reservations/${id}/departure/inspection`,attempt);
  const vehicleId=inspection.data?.vehicle.id;
  const vehicle=useRead<Record<string,{latest:VehicleFile|null}>>(vehicleOpen&&vehicleId?`vehicles/${vehicleId}/documents`:null,attempt);
  const versions=contracts.data?.versions??[],latest=versions[0];
  const original=latest?.files.find(file=>file.kind==='original'),signed=latest?.files.filter(file=>file.kind==='signed').at(-1);
  function openContract(file:Attachment,version:Version){setView({reservationId:id,contractFile:true,document:{id:file.id,kind:file.kind==='original'?'rental_contract':'signed_contract',version:version.version}});}
  const retry=<button type="button" className="secondary" onClick={()=>setAttempt(n=>n+1)}>Réessayer le chargement</button>;
  return <section className="da rq-panel" aria-label="Contrat et documents du dossier">
    <header className="da-heading"><div><h4 id={'rental-archive-'+id} tabIndex={-1}>Contrat et documents</h4><p>Les pièces utiles, réunies dans votre dossier.</p></div>
    <button type="button" className="secondary" disabled={signing} onClick={()=>setAttempt(n=>n+1)}><RefreshCw size={16}/> Actualiser les documents</button></header>
    <section className="da-current" aria-label="Contrat conservé"><h5><FileText size={20}/> Contrat de cette location</h5>
      {contracts.error?<div role="alert"><p>{contracts.error}</p>{retry}</div>:!contracts.data?<p role="status">Chargement du contrat…</p>:!latest?<p>Aucun contrat enregistré dans ce dossier.</p>:<>
        <strong className="da-reference">{latest.reference}</strong>
        <div className="da-actions">{signed&&<button type="button" className="primary" onClick={()=>openContract(signed,latest)}>Voir le contrat signé</button>}{original&&<button type="button" className="secondary" onClick={()=>openContract(original,latest)}>Voir le PDF original</button>}</div>
        {contracts.data.can_sign_rectification&&!signing&&<div className="da-actions"><button type="button" className="secondary" onClick={()=>setSigning(true)}>Signer / vérifier la version corrigée</button></div>}
        <p className={'da-status '+(signed?.verified?'is-verified':'')}>{signed?(signed.verified?'Copie signée vérifiée':'Copie signée · à vérifier'):'Copie signée non enregistrée'}</p>
        <details><summary>Historique des versions et copies conservées</summary>{versions.map(version=><div className="da-history" key={version.id}><strong>{version.reference}</strong><div className="da-actions">{version.files.map(file=><button type="button" className="secondary" key={file.id} onClick={()=>openContract(file,version)}>{file.kind==='original'?'Original':'Copie signée'} · fichier #{file.id}{file.verified?' · vérifiée':''}</button>)}</div></div>)}</details>
      </>}
    </section>
    {signing&&<section aria-label="Signature de la version corrigée"><p>V1 et ses signatures restent conservées. Les deux parties doivent signer à nouveau cette version corrigée.</p><DepartureContract id={id} correctionOnly onSaved={()=>{setAttempt(n=>n+1);onSaved();}} onBusyChange={value=>{setSigningBusy(value);onBusyChange(value);}} onDirtyChange={setSigningDirty}/><button type="button" className="secondary" disabled={signingBusy} onClick={()=>{if(signingDirty&&!window.confirm('Abandonner la saisie de signature non enregistrée ?'))return;setSigning(false);setSigningDirty(false);}}>Fermer la signature et revenir aux documents</button></section>}
    <section aria-label="Documents du client conservés"><h5>Documents du client</h5><p>Documents actuels du fichier client. Les informations du contrat émis restent celles de sa version conservée.</p>
      {!canReadCustomer?<p>La consultation des pièces du client est réservée au responsable.</p>:files.length===0?<p>Aucune photo de CIN / passeport ou de permis enregistrée.</p>:<div className="da-file-grid">{files.map(file=><button type="button" className="secondary da-file" key={file.id} onClick={()=>setView({customerId,document:{id:file.id,kind:file.slot,version:file.version}})}><FileText size={22}/><span><strong>{labels[file.slot]??file.slot}</strong><small>Version {file.version} · Consulter</small></span><ArrowUpRight size={17}/></button>)}</div>}
    </section>
    <section aria-label="Médias du contrôle de départ"><h5>Photos et vidéos du contrôle de départ</h5>
      {inspection.error?<div role="alert"><p>{inspection.error}</p>{retry}</div>:!inspection.data?<p role="status">Chargement du contrôle…</p>:!inspection.data.latest?<p>Aucun contrôle enregistré.</p>:<>
        <p>{inspection.data.latest.kilometers.toLocaleString('fr-FR')} km · carburant {inspection.data.latest.fuel_eighths}/8 · {inspection.data.latest.photos.length} fichier(s)</p><div className="da-media-grid">{inspection.data.latest.photos.map(file=><button type="button" className="da-media" key={file.id} onClick={()=>setView({reservationId:id,document:{id:file.id,kind:'departure_photo',version:inspection.data!.latest!.id}})}><MediaPreview id={id} file={file}/><span>{file.mime.startsWith('video/')?'Vidéo':'Photo'} · {file.caption||`fichier #${file.id}`}</span></button>)}</div>{inspection.data.latest.photos.length===0&&<p>Aucune photo ni vidéo jointe à ce contrôle.</p>}
      </>}
    </section>
    <details onToggle={event=>setVehicleOpen(event.currentTarget.open)}><summary>Documents du véhicule</summary><p>Documents actuels du fichier voiture.</p>
      {vehicleOpen&&(inspection.error?<p role="alert">Le fichier voiture ne peut pas être chargé.</p>:vehicle.error?<div role="alert"><p>{vehicle.error}</p>{retry}</div>:!vehicle.data?<p role="status">Chargement des documents du véhicule…</p>:<div className="da-actions">{Object.entries(vehicle.data).map(([kind,group])=>{const file=group.latest;return file?.has_file?<button type="button" className="secondary" key={kind} onClick={()=>setView({vehicleId,document:{id:file.id,kind,version:file.version}})}>{labels[kind]??kind} · V{file.version}</button>:<span key={kind}>{labels[kind]??kind} : aucun fichier joint.</span>;})}</div>)}
    </details>
    {view&&<DocumentViewer {...view} onClose={()=>setView(null)}/>}
  </section>;
}
