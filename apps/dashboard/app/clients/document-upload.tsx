'use client';
import FieldHelp from '../_components/field-help';
import DocumentCamera from './document-camera';
import {Camera,Upload} from 'lucide-react';
import './document-photo-input.css';

import { useEffect, useRef, useState, useId } from 'react';
import { api } from '../_lib/auth-client';

export type DocumentSlot = 'identity_front' | 'identity_back' | 'licence_front' | 'licence_back';
export type CustomerDocument = { id: number; slot: DocumentSlot; version: number; mime: string; size: number; created_at: string };
export type PendingPhoto = { file: File; key: string; previousId: number | null };
export type PendingPhotos = Partial<Record<DocumentSlot, PendingPhoto>>;

export function DocumentIcon({ licence = false }: { licence?: boolean }) {
  return <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
    <rect x="2.5" y="5" width="19" height="14" rx="3" />
    {licence ? <><path d="m6 13 1-3h4l1 3v3H6v-3ZM6 13h6M7 16v1m4-1v1" /><path d="M15 10h3m-3 4h3" /></> : <><circle cx="8" cy="10" r="1.5" /><path d="M5.5 15a2.5 2.5 0 0 1 5 0M14 10h4m-4 4h4" /></>}
  </svg>;
}

export default function PhotoTile<Slot extends string = DocumentSlot>({ slot, label, serverError = '', customerId, collection = "customers", record, pending, editable, disabled, onSelect, onRemove, onCheckingChange }: {
  slot: Slot; label: string; serverError?:string; customerId?: number; collection?: "customers" | "vehicles"; record?: Omit<CustomerDocument, "slot"> & { slot: Slot }; pending?: PendingPhoto;
  editable: boolean; disabled: boolean; onSelect: (slot: Slot, file: File) => void; onRemove: (slot: Slot) => void; onCheckingChange?:(slot:Slot,checking:boolean)=>void;
}) {
  const errorId=useId();
  const input = useRef<HTMLInputElement>(null);
  const camera = useRef<HTMLInputElement>(null);
  const [cameraOpen, setCameraOpen] = useState(false);
  const selection = useRef(0);
  const dragDepth = useRef(0);
  const [decoding,setDecoding] = useState(false);
  useEffect(()=>{onCheckingChange?.(slot,decoding);return()=>onCheckingChange?.(slot,false);},[slot,decoding,onCheckingChange]);
  useEffect(()=>()=>{selection.current++;},[]);
  useEffect(()=>{if(disabled||!editable){selection.current++;setDecoding(false);setCameraOpen(false);}},[disabled,editable]);
  const viewer = useRef<HTMLDialogElement>(null);
  const [url, setUrl] = useState('');
  const [imageError, setImageError] = useState('');
  const [fileError, setFileError] = useState('');
  const visibleError=fileError||serverError;
  const [dragging, setDragging] = useState(false);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const abort = new AbortController();
    let objectUrl = '';
    setUrl(''); setImageError('');
    if (pending) {
      objectUrl = URL.createObjectURL(pending.file); setUrl(objectUrl);
    } else if (record && customerId) {
      void (async () => {
        try {
          const response = await fetch(api + '/api/v1/' + collection + '/' + customerId + (collection === 'vehicles' ? '/photos/' : '/documents/') + record.id + '/content', { credentials: 'include', cache: 'no-store', headers: { Accept: 'image/*' }, signal: AbortSignal.any([abort.signal, AbortSignal.timeout(15000)]) });
          if (!response.ok) throw new Error('Aperçu indisponible. Réessayez.');
          const blob = await response.blob();
          if (abort.signal.aborted) return;
          objectUrl = URL.createObjectURL(blob); setUrl(objectUrl);
        } catch (e) { if (!abort.signal.aborted) setImageError(e instanceof Error ? e.message : 'Aperçu indisponible.'); }
      })();
    }
    return () => { abort.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [pending, record?.id, customerId, collection, retry]);

  async function select(file?: File) {
    if (!file || disabled || !editable) return;
    const attempt=++selection.current;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      setDecoding(false);setFileError('Choisissez une image JPG, PNG ou WebP.'); return;
    }
    if (file.size > 5 * 1024 * 1024) { setDecoding(false);setFileError('Cette image dépasse 5 Mo. Choisissez une version plus légère.'); return; }
    if(collection==='vehicles'){setFileError('');onSelect(slot,file);return;}
    setDecoding(true);setFileError('');
    const preview=URL.createObjectURL(file);
    try{
      const image=new Image();
      await new Promise<void>((resolve,reject)=>{image.onload=()=>resolve();image.onerror=()=>reject(Error('Cette image ne peut pas être lue. Reprenez la photo ou choisissez une autre image.'));image.src=preview;});
      if(collection==='customers'&&(image.naturalWidth>6000||image.naturalHeight>6000))throw Error('L’image ne doit pas dépasser 6000 pixels de largeur ou de hauteur.');
      if(attempt===selection.current)onSelect(slot,file);
    }catch(e){if(attempt===selection.current)setFileError((e as Error).message);}
    finally{URL.revokeObjectURL(preview);if(attempt===selection.current)setDecoding(false);}
  }
  return <div role="group" aria-label={label} className={'photo-tile ' + (visibleError?'field-upload-invalid ':'') + (dragging ? 'is-dragging ' : '') + (pending ? 'has-pending' : record ? 'has-photo' : '')}
    onDragOver={e => { e.preventDefault(); if (editable && !disabled) setDragging(true); }}
    onDragEnter={e=>{e.preventDefault();if(editable&&!disabled){dragDepth.current++;setDragging(true);}}}
    onDragLeave={()=>{dragDepth.current=Math.max(0,dragDepth.current-1);if(!dragDepth.current)setDragging(false);}}
    onDrop={e => { e.preventDefault();dragDepth.current=0;setDragging(false);if(disabled||!editable)return; if (e.dataTransfer.files.length > 1) { setFileError('Choisissez une seule image pour cette face.'); return; } void select(e.dataTransfer.files[0]); }}>
    <div className="photo-tile-label"><strong>{label}{editable && <FieldHelp field="photo" label={label}/>}</strong><span className={pending ? 'photo-state pending' : 'photo-state'}>{pending ? 'À enregistrer' : record ? 'Enregistrée' : 'Facultatif'}</span></div>
    <input ref={input} aria-invalid={!!visibleError} aria-describedby={visibleError?errorId:undefined} className="sr-only" tabIndex={-1} type="file" accept="image/jpeg,image/png,image/webp" aria-label={'Photo ' + label} disabled={disabled || !editable} onChange={e => { select(e.target.files?.[0]); e.target.value = ''; }} />
    {collection==='customers'&&<input ref={camera} className="sr-only" tabIndex={-1} type="file" accept="image/jpeg,image/png,image/webp" capture="environment" aria-label={'Caméra '+label} disabled={disabled||!editable} onChange={e=>{void select(e.target.files?.[0]);e.target.value='';}}/>}
    {url ? <button type="button" className="photo-preview" aria-label={'Agrandir ' + label} onClick={() => viewer.current?.showModal()}><img src={url} alt={label} onError={() => { setImageError('Cette image ne peut pas être affichée. Choisissez une autre photo.'); setUrl(''); }} /><span>↗ Agrandir</span></button> :
      imageError ? <div className="photo-load-error"><p>{imageError}</p>{record && <button className="text-button" type="button" onClick={() => setRetry(r => r + 1)}>Réessayer</button>}</div> :
      (record || pending) ? <div className="photo-placeholder" role="status">Chargement de l’image…</div> :
      <button type="button" className="photo-dropzone" disabled={!editable || disabled} onClick={() => input.current?.click()} aria-label={'Ajouter ' + label}><span className="upload-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 16V4m-4 4 4-4 4 4M4 15v5h16v-5" /></svg></span><strong>{editable ? 'Ajouter une photo' : 'Aucune photo'}</strong><small>{editable ? 'Glissez-la ici ou cliquez pour choisir' : 'À ajouter dans « Modifier le fichier »'}</small></button>}
    {(url || imageError) && <div className="photo-actions">
      {editable && collection!=='customers'&&<button type="button" disabled={disabled} className="text-button" onClick={() => input.current?.click()}>Choisir une autre photo</button>}
      {editable && pending && <button type="button" disabled={disabled} className="text-button muted-button" onClick={() => { selection.current++;setDecoding(false);setFileError(''); onRemove(slot); }}>Annuler l’ajout</button>}
      {!editable && record && <span>Version {record.version} · {Math.max(1, Math.round(record.size / 1024))} Ko</span>}
    </div>}
    {editable&&collection==='customers'&&<div className="photo-input-actions"><button type="button" className="photo-source-button" disabled={disabled} onClick={()=>setCameraOpen(true)}><Camera size={17}/>{pending?'Reprendre la photo':'Prendre une photo'}</button><button type="button" className="photo-source-button" disabled={disabled} onClick={()=>input.current?.click()}><Upload size={16}/>{pending||record?'Choisir une autre image':'Choisir une image'}</button><small>Ou glissez une image ici · JPG, PNG, WebP · 5 Mo maximum</small></div>}
    {cameraOpen&&<DocumentCamera label={label} onPhoto={file=>{void select(file);}} onClose={()=>setCameraOpen(false)} onChoose={()=>camera.current?.click()}/>}
    {decoding&&<p className="photo-decoding" role="status">Vérification de l’aperçu…</p>}
    {pending && <span className="photo-filename" title={pending.file.name}>{pending.file.name}</span>}
    {visibleError && <p id={errorId} className="photo-error" role="alert">{visibleError}</p>}
    <dialog ref={viewer} className="photo-viewer" aria-label={'Aperçu : ' + label} onCancel={e => e.stopPropagation()}>
      <div><strong>{label}</strong><button type="button" className="secondary" onClick={() => viewer.current?.close()}>Fermer l’aperçu</button></div>
      {url && <img src={url} alt={label} />}
    </dialog>
  </div>;
}
