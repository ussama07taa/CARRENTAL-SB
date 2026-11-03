'use client';

import {useEffect,useId,useRef,useState} from 'react';
import {ArrowLeftRight,ChevronLeft,ChevronRight,Expand,ImageOff,X} from 'lucide-react';
import {api} from '../_lib/auth-client';
import './inspection-photo-comparison.css';

type SavedPhoto={id:number;mime:string;caption:string|null;kind?:string};
type Draft={file:File;caption:string};
type Photo={key:string;caption:string;path?:string;file?:File;draft?:boolean};

function PhotoImage({photo,label}:{photo:Photo;label:string}){
  const [src,setSrc]=useState(''),[error,setError]=useState(''),[attempt,setAttempt]=useState(0);
  useEffect(()=>{
    const controller=new AbortController();let url='';
    setSrc('');setError('');
    const load=async()=>{
      try{
        let blob:Blob;
        if(photo.file)blob=photo.file;
        else{
          const response=await fetch(api+'/api/v1/'+photo.path,{credentials:'include',cache:'no-store',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(15000)])});
          if(!response.ok)throw Error(response.status===401?'Session expirée. Reconnectez-vous.':response.status===403?'Accès à la photo non autorisé.':'Photo indisponible.');
          blob=await response.blob();
        }
        if(!['image/jpeg','image/png','image/webp'].includes(blob.type.split(';')[0]))throw Error('Format de photo non pris en charge.');
        if(controller.signal.aborted)return;
        url=URL.createObjectURL(blob);setSrc(url);
      }catch(e){if(!controller.signal.aborted)setError(e instanceof Error?e.message:'Photo indisponible.');}
    };
    void load();
    return()=>{controller.abort();if(url)URL.revokeObjectURL(url);};
  },[photo.path,photo.file,attempt]);
  if(error)return <div className="ipc-placeholder" role="alert"><ImageOff aria-hidden="true"/><span>{error}</span><button type="button" className="secondary" onClick={()=>setAttempt(n=>n+1)}>Réessayer</button></div>;
  return src?<img src={src} alt={label+' — '+photo.caption} onError={()=>setError('Impossible d’afficher cette photo.')}/>:<div className="ipc-placeholder" role="status">Chargement de la photo…</div>;
}

function PhotoPane({label,date,photos,selected,onSelect,empty}:{label:string;date:string|null;photos:Photo[];selected:string;onSelect:(key:string)=>void;empty:string}){
  const selectId=useId();
  const found=photos.findIndex(photo=>photo.key===selected),index=found<0?0:found,photo=photos[index];
  return <section className="ipc-pane" aria-label={'Photos du '+label.toLowerCase()}>
    <header><strong>{label}</strong><span>{date??'Pas encore enregistré'}</span></header>
    {photo?<><label className="ipc-select" htmlFor={selectId}>Photo à comparer<select id={selectId} value={photo.key} onChange={e=>onSelect(e.target.value)}>{photos.map((item,i)=><option key={item.key} value={item.key}>{i+1}. {item.caption}{item.draft?' · à enregistrer':''}</option>)}</select></label>
      <div className="ipc-image"><PhotoImage key={photo.key} photo={photo} label={label}/>{photo.draft&&<span className="ipc-draft">À enregistrer</span>}</div>
      <nav className="ipc-controls" aria-label={'Parcourir les photos du '+label.toLowerCase()}>
        <button type="button" className="secondary" aria-label={'Photo précédente du '+label.toLowerCase()} disabled={index===0} onClick={()=>onSelect(photos[index-1].key)}><ChevronLeft size={18} aria-hidden="true"/></button>
        <span aria-live="polite">{index+1} / {photos.length}</span>
        <button type="button" className="secondary" aria-label={'Photo suivante du '+label.toLowerCase()} disabled={index===photos.length-1} onClick={()=>onSelect(photos[index+1].key)}><ChevronRight size={18} aria-hidden="true"/></button>
      </nav></>:<div className="ipc-placeholder ipc-empty"><ImageOff aria-hidden="true"/><p>{empty}</p></div>}
  </section>;
}

export default function InspectionPhotoComparison({reservationId,departure,returned,drafts,departureDate,returnDate}:{reservationId:number;departure:SavedPhoto[];returned:SavedPhoto[];drafts:Draft[];departureDate:string;returnDate:string|null}){
  const [left,setLeft]=useState(''),[right,setRight]=useState(''),[expanded,setExpanded]=useState(false);
  const modal=useRef<HTMLDialogElement>(null),opener=useRef<HTMLButtonElement>(null),title=useId();
  const prefix='reservations/'+reservationId+'/';
  const before:Photo[]=departure.filter(p=>p.mime.startsWith('image/')).map(p=>({key:'departure-'+p.id,caption:p.caption||'Photo du départ',path:prefix+'departure/inspection/photos/'+p.id+'/content'}));
  const after:Photo[]=[...returned.filter(p=>p.kind==='photo'&&p.mime.startsWith('image/')).map(p=>({key:'return-'+p.id,caption:p.caption||'Photo du retour',path:prefix+'return/files/'+p.id+'/content'})),...drafts.filter(p=>p.file.type.startsWith('image/')).map((p,i)=>({key:'draft-'+i+'-'+p.file.name+'-'+p.file.lastModified,caption:p.caption||'Nouvelle photo du retour',file:p.file,draft:true}))];
  useEffect(()=>{
    if(!expanded)return;
    const dialog=modal.current;dialog?.showModal();
    return()=>{dialog?.close();opener.current?.focus({preventScroll:true});};
  },[expanded]);
  const panes=<div className="ipc-grid">
    <PhotoPane label="Départ" date={departureDate} photos={before} selected={left} onSelect={setLeft} empty="Aucune photo conservée pour ce départ."/>
    <PhotoPane label="Retour" date={returnDate} photos={after} selected={right} onSelect={setRight} empty="Ajoutez les photos pendant le contrôle du retour pour les comparer."/>
  </div>;
  return <section className="ipc" aria-label="Comparer les photos du départ et du retour">
    <div className="ipc-heading"><div><h5><ArrowLeftRight size={19} aria-hidden="true"/>Comparer les photos</h5><p>Choisissez le même angle de chaque côté pour examiner les différences.</p></div>
      <button ref={opener} type="button" className="secondary" onClick={()=>setExpanded(true)}><Expand size={17} aria-hidden="true"/>Agrandir</button></div>
    {!expanded&&panes}
    <p className="ipc-note">Photos du départ enregistré et du contrôle de retour affiché. Les dommages restent à constater dans le contrôle.</p>
    {expanded&&<dialog ref={modal} className="ipc-dialog" aria-labelledby={title} onCancel={e=>{e.preventDefault();setExpanded(false);}}>
      <header className="ipc-heading"><h5 id={title}>Comparaison départ / retour</h5><button autoFocus type="button" className="secondary" onClick={()=>setExpanded(false)}><X size={18} aria-hidden="true"/>Fermer</button></header>
      {panes}
    </dialog>}
  </section>;
}
