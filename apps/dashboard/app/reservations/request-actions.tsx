 'use client';
import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {MoreHorizontal,Trash2,RotateCcw,X,Eye,Ban} from 'lucide-react';
import {api,apiMutation} from '../_lib/auth-client';
import './request-actions.css';
type Eligibility={cancelled?:boolean;restore_status?:string;can_trash:boolean;can_cancel_quick:boolean;can_restore:boolean;reason:string|null};
type Action='trash'|'restore'|'cancel_quick';
export default function RequestActions({id,reference,trash,onDone,onOpen}:{id:number;reference:string;trash:boolean;onDone:(message:string,action:Action)=>void;onOpen:()=>void}){
 const [opened,setOpened]=useState(false);
 const trigger=useRef<HTMLButtonElement>(null);
 return <><button ref={trigger} aria-haspopup="dialog" aria-expanded={opened} type="button" title={trash?'Restaurer la demande':'Actions de la demande'} aria-label={'Actions '+reference} onClick={()=>setOpened(v=>!v)}><MoreHorizontal size={20}/></button>{opened&&<ActionsDialog anchor={trigger.current} id={id} reference={reference} trash={trash} onClose={()=>setOpened(false)} onDone={onDone} onOpen={onOpen}/>}</>;
}
function ActionsDialog({anchor,id,reference,trash,onClose,onDone,onOpen}:{anchor:HTMLButtonElement|null;id:number;reference:string;trash:boolean;onClose:()=>void;onDone:(message:string,action:Action)=>void;onOpen:()=>void}){
 const dialog=useRef<HTMLDialogElement>(null),reasonField=useRef<HTMLTextAreaElement>(null),lock=useRef(false),key=useRef(crypto.randomUUID());
 const [eligibility,setEligibility]=useState<Eligibility|null>(null),[error,setError]=useState(''),[reason,setReason]=useState(''),[invalid,setInvalid]=useState(false),[busy,setBusy]=useState(false),[uncertain,setUncertain]=useState(false),[reload,setReload]=useState(0),[action,setAction]=useState<Action|null>(null);
 const guarded=useRef(false);guarded.current=busy||uncertain;
 const close=useRef(onClose);close.current=onClose;
 useEffect(()=>{
  const panel=dialog.current;if(!panel||!anchor)return;
  panel.show();
  function position(){
   if(!panel||!anchor)return;
   const a=anchor.getBoundingClientRect(),w=panel.offsetWidth,h=panel.offsetHeight;
   const below=a.bottom+8;
   panel.style.left=Math.max(12,Math.min(a.right-w,window.innerWidth-w-12))+'px';
   panel.style.top=Math.max(12,below+h<=window.innerHeight-12?below:a.top-h-8)+'px';
  }
  position();const observer=new ResizeObserver(position);observer.observe(panel);
  function outside(e:PointerEvent){if(!guarded.current&&!panel?.contains(e.target as Node)&&!anchor?.contains(e.target as Node))close.current();}
  function escape(e:KeyboardEvent){if(e.key==='Escape'){e.preventDefault();e.stopPropagation();if(!guarded.current)close.current();}}
  document.addEventListener('pointerdown',outside);panel.addEventListener('keydown',escape);
  window.addEventListener('resize',position);window.addEventListener('scroll',position,true);
  return()=>{observer.disconnect();document.removeEventListener('pointerdown',outside);panel.removeEventListener('keydown',escape);window.removeEventListener('resize',position);window.removeEventListener('scroll',position,true);if(anchor.isConnected)anchor.focus();};
 },[anchor]);
 useEffect(()=>{const c=new AbortController();setError('');setEligibility(null);fetch(`${api}/api/v1/reservations/${id}/disposal`,{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([c.signal,AbortSignal.timeout(10000)])}).then(async r=>{const b=await r.json();if(!r.ok)throw Error(b.message??'Vérification impossible.');return b;}).then(b=>{if(!c.signal.aborted)setEligibility(b);}).catch(e=>{if(!c.signal.aborted)setError(e.message);});return()=>c.abort();},[id,reload]);
 function choose(value:Action){setAction(value);setReason(value==='restore'?'Suppression effectuée par erreur':'');setInvalid(false);setError('');key.current=crypto.randomUUID();}
 async function confirm(){
  if(!action||lock.current)return;
  if(reason.trim().length<3){setInvalid(true);reasonField.current?.focus();return;}
  lock.current=true;setBusy(true);setError('');
  try{const r=await apiMutation(`reservations/${id}/disposal`,'POST',{action,reason:reason.trim(),request_key:key.current});const b=await r.json();
   if(!r.ok){setUncertain(false);if(b.errors?.reason){setInvalid(true);reasonField.current?.focus();}throw Error(b.message??'L’action a échoué.');}
   onDone(action==='trash'?'Demande déplacée dans la corbeille.':action==='restore'?(eligibility?.restore_status==='annulee'?'Demande restaurée dans « Annulées ».':'Demande restaurée. Aucun créneau n’est réservé.'):'Demande annulée. Historique conservé.',action);onClose();
  }catch(e){if(e instanceof TypeError || (e instanceof DOMException&&e.name==='TimeoutError'))setUncertain(true);setError(e instanceof Error?e.message:'Connexion interrompue. Réessayez la même action.');}
  finally{lock.current=false;setBusy(false);}
 }
 const allowed=action&&eligibility?.[action==='trash'?'can_trash':action==='restore'?'can_restore':'can_cancel_quick'];
 return createPortal(<dialog className={`request-actions-dialog${action?' is-confirming':''}`} ref={dialog} aria-labelledby="request-actions-title" onClick={e=>e.stopPropagation()} onCancel={e=>{e.preventDefault();e.stopPropagation();if(!busy&&!uncertain)onClose();}}>
  <header><div><small>{reference}</small><h2 id="request-actions-title">{!action?'Actions de la demande':action==='trash'?'Déplacer dans la corbeille ?':action==='restore'?'Restaurer cette demande ?':'Annuler cette demande ?'}</h2></div><button type="button" className="secondary" aria-label="Fermer les actions" disabled={busy||uncertain} onClick={onClose}><X size={18}/></button></header>
  {!eligibility&&!error&&<p role="status">Vérification du dossier…</p>}
  {error&&<p className="request-action-error" role="alert">{error}</p>}
  {!eligibility&&error&&<button className="secondary" onClick={()=>setReload(n=>n+1)}>Réessayer</button>}
  {eligibility&&!action&&<div className="request-action-choices">
   {!trash&&<button onClick={()=>{onClose();onOpen();}}><Eye size={18}/>Voir le dossier</button>}
   {!trash&&!eligibility.cancelled&&<button disabled={!eligibility.can_cancel_quick} onClick={()=>choose('cancel_quick')}><Ban size={18}/>Annuler la demande</button>}
   {!trash&&<button className="danger" disabled={!eligibility.can_trash} onClick={()=>choose('trash')}><Trash2 size={18}/>Supprimer · vers la corbeille</button>}
   {trash&&<button disabled={!eligibility.can_restore} onClick={()=>choose('restore')}><RotateCcw size={18}/>Restaurer la demande</button>}
   {eligibility.reason&&<p>{eligibility.reason}</p>}
  </div>}
  {action&&<form onSubmit={e=>{e.preventDefault();void confirm();}}>
   <p>{action==='trash'?'La demande sera masquée des listes et du planning. Vous pourrez la restaurer depuis la corbeille. Aucune donnée ne sera effacée.':action==='restore'?(eligibility?.restore_status==='annulee'?'Elle reviendra dans « Annulées ». Son annulation et son historique seront conservés.':'Elle reviendra dans les demandes. La disponibilité devra être revérifiée avant toute réservation.'):'Elle restera consultable dans « Annulées ». Aucun paiement ni remboursement ne sera créé.'}</p>
   <label htmlFor="request-action-reason">Motif</label><textarea id="request-action-reason" ref={reasonField} value={reason} maxLength={1000} disabled={busy||uncertain} aria-invalid={invalid} aria-describedby={invalid?'request-action-reason-error':undefined} onChange={e=>{setReason(e.target.value);setInvalid(false);}}/>
   {invalid&&<p id="request-action-reason-error" className="request-action-error">Indiquez un motif de 3 caractères minimum.</p>}
   {uncertain&&<p role="status">Réponse non reçue. Réessayez la même action : elle ne sera pas exécutée deux fois.</p>}
   <footer><button type="button" className="secondary" disabled={busy||uncertain} onClick={()=>setAction(null)}>Retour</button><button type="submit" className="primary" disabled={busy||!allowed}>{busy?'Enregistrement…':uncertain?'Réessayer':action==='trash'?'Mettre à la corbeille':action==='restore'?'Restaurer':'Confirmer l’annulation'}</button></footer>
  </form>}
 </dialog>,document.body);
}
