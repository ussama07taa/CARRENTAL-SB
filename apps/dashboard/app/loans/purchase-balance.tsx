import {money} from './loan-types';

export default function PurchaseBalance({apportRemaining,installmentsRemaining,installmentCount}:{apportRemaining:number;installmentsRemaining:number;installmentCount:number}){
 return <section className="lc-purchase-balance" aria-label="Ce qu’il reste à payer">
  <h3>Ce qu’il reste à payer</h3>
  <div className="lc-metrics">
   <div><span>Reste de l’apport initial</span><strong>{money(apportRemaining)}</strong><small>{apportRemaining===0?'Aucun apport restant':'La part à verser au départ'}</small></div>
   <div><span>Reste des mensualités</span><strong>{money(installmentsRemaining)}</strong><small>{installmentCount} échéance{installmentCount===1?'':'s'} non soldée{installmentCount===1?'':'s'}</small></div>
   <div className="lc-emphasis"><span>Total restant à payer</span><strong>{money(apportRemaining+installmentsRemaining)}</strong><small>Apport + toutes les mensualités</small></div>
  </div>
  <p className="lc-note">Ce total couvre tous les versements restants, selon leurs dates prévues.</p>
 </section>
}
