'use client';

import {useEffect,useRef} from 'react';
import {createPortal} from 'react-dom';
import dynamic from 'next/dynamic';
import {X} from 'lucide-react';
import {type ActivityRow,refreshActivity} from './activity-feed';
import '../vehicles/vehicles.css';
import '../loans/loans.css';

const MaintenanceFile=dynamic(()=>import('../vehicles/maintenance-file'));
const LoanFile=dynamic(()=>import('../loans/loan-file'));
const VehicleDocuments=dynamic(()=>import('../vehicles/vehicle-documents'));
function Documents({row,onClose}:{row:ActivityRow;onClose:()=>void}){
 const ref=useRef<HTMLDialogElement>(null);
 useEffect(()=>{const origin=document.activeElement as HTMLElement;ref.current?.showModal();return()=>origin?.focus();},[]);
 return createPortal(<dialog className="activity-document-dialog" ref={ref} onCancel={onClose} aria-labelledby="activity-document-title"><header><div><span>DOCUMENT DU VÉHICULE</span><h2 id="activity-document-title">{row.subject}</h2><p>{row.title}</p></div><button className="secondary" aria-label="Fermer les documents" onClick={onClose}><X size={20}/></button></header><VehicleDocuments vehicleId={row.target!.vehicle_id!} onlyKind={row.target!.kind} onSaved={refreshActivity}/></dialog>,document.body);
}
export default function ActivityTarget({row,today,onClose,canManageFinance}:{row:ActivityRow|null;today:string;onClose:()=>void;canManageFinance:boolean}){
 if(!row?.target||row.group==='finance'&&!canManageFinance)return null;
 const target=row.target;
 if(target.type==='documents')return <Documents row={row} onClose={onClose}/>;
 if(target.type==='loan'&&canManageFinance)return <LoanFile id={target.id!} paymentOnly={!target.purchase} initialInstallmentId={target.installment_id??undefined} initialPurchasePayment={target.purchase} onClose={onClose} onChanged={refreshActivity}/>;
 if(target.type==='maintenance')return <MaintenanceFile vehicleId={target.vehicle_id!} vehicleLabel={row.subject} entryId={target.entry_id??null} today={today} odometer={target.odometer??{value:null,date:null}} initialKind={target.kind} initialTitle={target.entry_id?undefined:row.title} initialOperation={target.operation??undefined} initialFollowupKey={target.followup_key??undefined} onClose={onClose} onSaved={refreshActivity}/>;
 return null;
}
