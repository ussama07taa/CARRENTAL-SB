 'use client';
import {useEffect,useRef,useState} from 'react';
import {Camera,Plus,RotateCw,Trash2,Star,ArrowLeft,ArrowRight,Upload} from 'lucide-react';
import PhotoTile,{type PendingPhoto} from '../clients/document-upload';
import {apiMutation} from '../_lib/auth-client';
import type {Photo,Slot} from './vehicles';
import './vehicle-photo-gallery.css';
const slots:Slot[]=['cover','front','rear','interior','boot','detail_1','detail_2','detail_3'];
type Pending=Partial<Record<Slot,PendingPhoto>>;
function Thumb({file,onOpen}:{file:File;onOpen:(url:string)=>void}){
 const [url,setUrl]=useState('');
 useEffect(()=>{const next=URL.createObjectURL(file);setUrl(next);return()=>URL.revokeObjectURL(next);},[file]);
 return <button type="button" className="vg-thumb" onClick={()=>onOpen(url)} aria-label={'Agrandir '+file.name}>{url&&<img src={url} alt={file.name} draggable={false}/>}<span>Agrandir</span></button>;
}
export default function VehiclePhotoGallery({photos,pending,onChange,disabled,vehicleId,errors,onBusy,onSaved}:{photos:Photo[];pending:Pending;onChange:(value:Pending)=>void;disabled:boolean;vehicleId?:number;errors:Record<string,string[]>;onBusy:(busy:boolean)=>void;onSaved:(photos:Photo[])=>void}){
 const input=useRef<HTMLInputElement>(null),camera=useRef<HTMLInputElement>(null),viewer=useRef<HTMLDialogElement>(null);
 const [message,setMessage]=useState(''),[busy,setBusy]=useState(false),[preview,setPreview]=useState('');
 const request=useRef<{signature:string;key:string}|null>(null);
 const locked=useRef(false),alive=useRef(true);const drag=useRef<Slot|null>(null);
 useEffect(()=>{alive.current=true;return()=>{alive.current=false;};},[]);
 const occupied=slots.filter(slot=>pending[slot]||photos.some(p=>p.slot===slot));
 function finish(){locked.current=false;if(alive.current){setBusy(false);onBusy(false);}}
 async function add(files:FileList|File[]|null,target?:Slot){
  if(!files||disabled||locked.current)return;
  locked.current=true;setBusy(true);onBusy(true);setMessage('');
  const next={...pending};const failures:string[]=[];
  try{for(const file of Array.from(files)){
   const slot=target??slots.find(s=>!next[s]&&!photos.some(p=>p.slot===s));
   if(!slot){failures.push('La galerie contient déjà 8 photos. Retirez un ajout ou remplacez une photo.');break;}
   if(!['image/jpeg','image/png','image/webp'].includes(file.type)){failures.push(file.name+' : choisissez JPG, PNG ou WebP.');continue;}
   if(file.size>5*1024*1024){failures.push(file.name+' : maximum 5 Mo.');continue;}
   try{const bitmap=await createImageBitmap(file);const valid=bitmap.width<=6000&&bitmap.height<=6000;bitmap.close();if(!valid)throw Error('dimensions');}
   catch{failures.push(file.name+' : image illisible ou dimensions supérieures à 6000 pixels.');continue;}
   next[slot]={file,key:crypto.randomUUID(),previousId:photos.find(p=>p.slot===slot)?.id??null};
  }
  if(alive.current){onChange(next);setMessage(failures.join(' '));}
  }finally{finish();}
 }
 async function manage(action:'swap'|'remove',slot:Slot,target?:Slot){
  if(disabled||locked.current||!vehicleId)return;
  if(Object.keys(pending).length){setMessage('Enregistrez ou retirez les nouveaux ajouts avant de modifier les photos enregistrées.');return;}
  if(action==='remove'&&!window.confirm(slot==='cover'?'Retirer la photo principale ? La voiture ne sera plus affichée sur le site tant qu’une autre photo principale n’est pas définie. L’original reste dans l’historique.':'Retirer cette photo de la galerie ? L’original reste dans l’historique.'))return;
  const body={action,slot,...(target?{target}:{}),expected:Object.fromEntries(photos.map(p=>[p.slot,p.id]))};
  const signature=JSON.stringify(body);if(request.current?.signature!==signature)request.current={signature,key:crypto.randomUUID()};
  locked.current=true;setBusy(true);onBusy(true);setMessage('');
  try{const response=await apiMutation('vehicles/'+vehicleId+'/photos/manage','POST',{...body,request_key:request.current.key});const result=await response.json();if(!response.ok)throw Error(result.message??'Modification impossible. Réessayez.');if(alive.current){onSaved(result.photos);request.current=null;}}
  catch(e){if(alive.current)setMessage(e instanceof Error?e.message:'Connexion impossible. Réessayez.');}finally{finish();}
 }
 function swap(from:Slot,to:Slot){
  if(disabled||busy||from===to)return;
  if(!pending[from]&&photos.some(p=>p.slot===from)){void manage('swap',from,to);return;}
  if(!pending[from])return;
  if(photos.some(p=>p.slot===from||p.slot===to)){setMessage('Pour réorganiser les photos déjà enregistrées, conservez leur emplacement. Les nouveaux ajouts peuvent être réordonnés entre eux.');return;}
  const next={...pending},source=next[from],other=next[to];
  next[to]={...source!,key:crypto.randomUUID(),previousId:null};
  if(other)next[from]={...other,key:crypto.randomUUID(),previousId:null};else delete next[from];
  onChange(next);setMessage('');
 }
 async function rotate(slot:Slot){
  if(disabled||locked.current||!pending[slot])return;
  locked.current=true;setBusy(true);onBusy(true);setMessage('');
  try{const original=pending[slot]!;const bitmap=await createImageBitmap(original.file);const canvas=document.createElement('canvas');canvas.width=bitmap.height;canvas.height=bitmap.width;const ctx=canvas.getContext('2d');if(!ctx){bitmap.close();throw Error();}ctx.translate(canvas.width,0);ctx.rotate(Math.PI/2);ctx.drawImage(bitmap,0,0);bitmap.close();const blob=await new Promise<Blob|null>(resolve=>canvas.toBlob(resolve,original.file.type,0.94));if(!blob||blob.size>5*1024*1024)throw Error();if(alive.current)onChange({...pending,[slot]:{...original,file:new File([blob],original.file.name,{type:blob.type}),key:crypto.randomUUID()}});
  }catch{if(alive.current)setMessage('Rotation impossible. Choisissez une image plus légère.');}finally{finish();}
 }
 const frozen=disabled||busy;
 return <div className="vg-gallery">
  <div className="vg-upload" onDragOver={e=>e.preventDefault()} onDrop={e=>{e.preventDefault();void add(e.dataTransfer.files);}}>
   <Upload size={24} aria-hidden="true"/><strong>Ajoutez les photos de votre voiture</strong><p>Choisissez plusieurs images ou glissez-les ici.</p>
   <div className="vg-sources"><button type="button" className="v-save" disabled={frozen||occupied.length===8} onClick={()=>input.current?.click()}><Plus size={17}/> Ajouter des photos</button><button type="button" className="secondary" disabled={frozen||occupied.length===8} onClick={()=>camera.current?.click()}><Camera size={17}/> Prendre une photo</button></div>
   <small>JPG, PNG, WebP · 5 Mo par photo · {occupied.length}/8 photos · Facultatif</small>
   <input ref={input} type="file" className="sr-only" tabIndex={-1} aria-label="Ajouter des photos du véhicule" multiple accept="image/jpeg,image/png,image/webp" disabled={frozen} onChange={e=>{void add(e.target.files);e.target.value='';}}/>
   <input ref={camera} type="file" className="sr-only" tabIndex={-1} aria-label="Prendre une photo du véhicule" capture="environment" accept="image/jpeg,image/png,image/webp" disabled={frozen} onChange={e=>{void add(e.target.files);e.target.value='';}}/>
  </div>
  {busy&&<p role="status">Préparation des photos…</p>}{message&&<p className="vg-error" role="alert">{message}</p>}
  {photos.length>0&&<p className="vg-hint">Les changements de la galerie enregistrée sont appliqués immédiatement. Les nouveaux ajouts et remplacements sont conservés après « Enregistrer ».</p>}
  {occupied.length>0&&<><p className="vg-hint">La photo principale apparaît dans le catalogue. Déplacez les photos ou utilisez les flèches pour les ordonner.</p><div className="vg-grid">{occupied.map((slot,i)=>{
   const item=pending[slot],saved=photos.find(p=>p.slot===slot);
   return <article key={slot} className={'vg-card '+(slot==='cover'?'vg-cover':'')} draggable={!frozen&&((!!item&&!saved)||(!item&&Object.keys(pending).length===0))} onDragStart={e=>{drag.current=slot;e.dataTransfer.setData('text/plain',slot);}} onDragEnd={()=>{drag.current=null;}} onDragOver={e=>{if(drag.current)e.preventDefault();}} onDrop={e=>{e.preventDefault();e.stopPropagation();if(drag.current)swap(drag.current,slot);drag.current=null;}}>
    {item?<><header><strong>{slot==='cover'?'Photo principale':'Photo '+(i+1)}</strong><small>À enregistrer</small></header><Thumb file={item.file} onOpen={url=>{setPreview(url);viewer.current?.showModal();}}/>
    <div className="vg-tools"><button type="button" disabled={frozen} aria-label={'Tourner la photo '+(i+1)} title="Tourner" onClick={()=>void rotate(slot)}><RotateCw size={17}/></button><button type="button" disabled={frozen} aria-label={'Retirer l’ajout '+(i+1)} title="Retirer cet ajout" onClick={()=>{const next={...pending};delete next[slot];if(slot==='cover'&&!photos.some(p=>p.slot==='cover')){const first=slots.find(s=>next[s]&&!photos.some(p=>p.slot===s));if(first){next.cover={...next[first]!,key:crypto.randomUUID(),previousId:null};delete next[first];}}onChange(next);}}><Trash2 size={17}/></button>
    {!saved&&<><button type="button" disabled={frozen||i===0||!!photos.find(p=>p.slot===occupied[i-1])} aria-label={'Déplacer la photo '+(i+1)+' avant'} onClick={()=>swap(slot,occupied[i-1])}><ArrowLeft size={17}/></button><button type="button" disabled={frozen||i===occupied.length-1||!!photos.find(p=>p.slot===occupied[i+1])} aria-label={'Déplacer la photo '+(i+1)+' après'} onClick={()=>swap(slot,occupied[i+1])}><ArrowRight size={17}/></button></>}
    </div>{slot!=='cover'&&!saved&&!photos.some(p=>p.slot==='cover')&&<button className="vg-primary" type="button" disabled={frozen} onClick={()=>swap(slot,'cover')}><Star size={15}/> Définir comme principale</button>}{errors['photo_'+slot]?.[0]&&<p className="vg-error" role="alert">{errors['photo_'+slot][0]}</p>}</>:<PhotoTile<Slot> slot={slot} label={slot==='cover'?'Photo principale':'Photo '+(i+1)} record={saved} customerId={vehicleId} collection="vehicles" editable disabled={frozen} onSelect={(s,file)=>void add([file],s)} onRemove={()=>{}} serverError={errors['photo_'+slot]?.[0]}/>}
    {!item&&saved&&<><div className="vg-tools"><button type="button" disabled={frozen||Object.keys(pending).length>0} aria-label={'Retirer la photo enregistrée '+(i+1)} title="Retirer de la galerie" onClick={()=>void manage('remove',slot)}><Trash2 size={17}/></button><button type="button" disabled={frozen||Object.keys(pending).length>0||i===0} aria-label={'Déplacer la photo enregistrée '+(i+1)+' avant'} onClick={()=>void manage('swap',slot,occupied[i-1])}><ArrowLeft size={17}/></button><button type="button" disabled={frozen||Object.keys(pending).length>0||i===occupied.length-1} aria-label={'Déplacer la photo enregistrée '+(i+1)+' après'} onClick={()=>void manage('swap',slot,occupied[i+1])}><ArrowRight size={17}/></button></div>{slot!=='cover'&&<button className="vg-primary" type="button" disabled={frozen||Object.keys(pending).length>0} onClick={()=>void manage('swap',slot,'cover')}><Star size={15}/> Définir comme principale</button>}</>}
   </article>;
  })}</div></>}
  <dialog ref={viewer} className="photo-viewer" aria-label="Aperçu de la photo" onCancel={e=>e.stopPropagation()}><div><strong>Aperçu de la photo</strong><button type="button" className="secondary" onClick={()=>viewer.current?.close()}>Fermer l’aperçu</button></div>{preview&&<img src={preview} alt="Photo du véhicule agrandie"/>}</dialog>
 </div>;
}
