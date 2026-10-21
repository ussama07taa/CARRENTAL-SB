'use client';

import {Fragment} from 'react';
import {Field,type Errors} from './loan-controls';
import {cents,date,money} from './loan-types';

export type ScheduleRow={due:string;amount:string;opening:string};
const pageSize=12;

export default function LoanSchedule({rows,mode,editing,page,errors,onEdit,onPage,onChange,onDone}:{
 rows:ScheduleRow[];
 mode:'new'|'existing';
 editing:number|null;
 page:number;
 errors:Errors;
 onEdit:(index:number|null)=>void;
 onPage:(page:number)=>void;
 onChange:(index:number,field:keyof ScheduleRow,value:string)=>void;
 onDone:()=>void;
}){
 const pages=Math.ceil(rows.length/pageSize);
 const value=(input:string)=>{try{return cents(input||'0');}catch{return null;}};
 return <>
  <div className="lc-table-wrap">
   <table className="lc-table lc-schedule-table">
    <thead><tr><th>Échéance</th><th>Montant prévu</th>{mode==='existing'&&<th>Déjà payé avant le suivi</th>}<th>Reste à régler</th><th>Action</th></tr></thead>
    <tbody>{rows.slice(page*pageSize,(page+1)*pageSize).map((row,offset)=>{
     const i=page*pageSize+offset,rawAmount=value(row.amount),amount=rawAmount!==null&&rawAmount>0?rawAmount:null,opening=value(row.opening);
     const invalid=Object.keys(errors).some(k=>k.startsWith(`installments.${i}.`));
     return <Fragment key={i}>
      <tr className={editing===i?'lc-row-active':undefined}>
       <td data-label="Échéance"><strong>N° {i+1}</strong><small>{date(row.due)}</small></td>
       <td data-label="Montant prévu">{amount===null?'À vérifier':money(amount)}</td>
       {mode==='existing'&&<td data-label="Déjà payé">{opening===null?'À vérifier':money(opening)}</td>}
       <td data-label="Reste à régler"><strong>{amount===null||opening===null?'À vérifier':money(amount-opening)}</strong>{amount!==null&&amount===opening&&<small className="lc-schedule-paid">✓ Réglée avant le suivi</small>}</td>
       <td className="lc-row-action"><button type="button" className="secondary" aria-expanded={editing===i} aria-controls={'loan-row-'+i} onClick={()=>onEdit(i)} aria-label={'Modifier échéance '+(i+1)}>Modifier{invalid?' · À vérifier':''}</button></td>
      </tr>
      {editing===i&&<tr className="lc-row-editor"><td colSpan={mode==='existing'?5:4}>
       <div id={'loan-row-'+i} className="lc-edit-one" role="group" aria-label={'Modifier échéance '+(i+1)}>
        <div className="lc-section-title"><h4>Modifier l’échéance {i+1}</h4><span>Seule cette ligne est ajustée.</span></div>
        <div className={'lc-grid '+(mode==='existing'?'lc-grid-three':'')}>
         <Field name={`installments.${i}.due_on`} help="loan_due_date" label="Date prévue" type="date" value={row.due} onChange={e=>onChange(i,'due',e.target.value)} error={errors[`installments.${i}.due_on`]}/>
         <Field name={`installments.${i}.amount_centimes`} help="loan_due_amount" label="Montant prévu · DH" inputMode="decimal" value={row.amount} onChange={e=>onChange(i,'amount',e.target.value)} error={errors[`installments.${i}.amount_centimes`]}/>
         {mode==='existing'&&<Field name={`installments.${i}.opening_paid_centimes`} help="loan_opening" label="Déjà payé avant le suivi · DH" inputMode="decimal" value={row.opening} onChange={e=>onChange(i,'opening',e.target.value)} error={errors[`installments.${i}.opening_paid_centimes`]}/>}
        </div>
        <button type="button" className="primary" onClick={onDone}>Valider cette ligne</button>
       </div>
      </td></tr>}
     </Fragment>;
    })}</tbody>
   </table>
  </div>
  {pages>1&&<div className="lc-paging"><span>Échéances {page*pageSize+1} à {Math.min((page+1)*pageSize,rows.length)} sur {rows.length}</span><button type="button" className="secondary" disabled={page===0} onClick={()=>onPage(page-1)}>Précédentes</button><button type="button" className="secondary" disabled={page===pages-1} onClick={()=>onPage(page+1)}>Suivantes</button></div>}
 </>;
}
