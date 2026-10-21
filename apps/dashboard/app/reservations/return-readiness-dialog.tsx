'use client';
import {useEffect,useRef,useState} from 'react';
import RentalReturnPanel from './rental-return';
export type ReturnBlocker={reservation_id:number;return_id:number;vehicle_id:number;vehicle_name:string;plate:string};
export default function ReturnReadinessDialog({currentId,blocker,onClose}:{currentId:number;blocker:ReturnBlocker;onClose:()=>void}){
 const dialog=useRef<HTMLDialogElement>(null),[busy,setBusy]=useState(false),[dirty,setDirty]=useState(false),[saved,setSaved]=useState(false);
 useEffect(()=>{const node=dialog.current;node?.showModal();return()=>node?.close();},[]);
 function close(){if(busy)return;if(dirty&&!window.confirm('Fermer sans enregistrer la saisie en cours ?'))return;onClose();}
 return <dialog ref={dialog} className="du-return-dialog" aria-labelledby="return-readiness-title" onCancel={e=>{e.preventDefault();close();}}>
  <header><h3 id="return-readiness-title">Disponibilité de {blocker.vehicle_name}</h3><button type="button" className="secondary" disabled={busy} onClick={close} aria-label="Fermer la disponibilité">Fermer</button></header>
  <p><strong>{blocker.plate}</strong> · Retour conservé dans DEM-{blocker.reservation_id}.</p>
  <p>Vous restez dans DEM-{currentId}. Le contrôle ci-dessous concerne uniquement cette voiture, même si une autre voiture l’a remplacée dans le dossier précédent.</p>
  <p>Revoyez le contrôle de retour, puis choisissez « Valider la voiture prête » si elle est prête. Si le contrôle manque ou signale une anomalie, complétez-le avant la validation.</p>
  <RentalReturnPanel id={blocker.reservation_id} returnId={blocker.return_id} onSaved={()=>setSaved(true)} onBusyChange={setBusy} onDirtyChange={setDirty}/>
  {saved&&<p role="status">Opération enregistrée. Revenez aux contrôles de départ pour vérifier la situation actualisée.</p>}
  <button type="button" className="primary" disabled={busy} onClick={close}>Revenir aux contrôles de DEM-{currentId}</button>
 </dialog>;
}
