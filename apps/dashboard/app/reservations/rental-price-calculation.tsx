'use client';

import {Calculator, RefreshCw} from 'lucide-react';
import FieldHelp from '../_components/field-help';
import {localDate} from './request-types';

export type RentalCalculation = {
  version_id:number;
  total_centimes:number;
  rule:string;
  agreed_total_centimes?:number;
  difference_centimes?:number;
  agreement_mode?:'calculated'|'negotiated';
  lines:{starts_local:string;ends_local:string;label:string;daily_centimes:number;half_day_units:number;twice_centimes:number}[];
};
const money=(n:number)=>new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n/100)+' DH';

export function CalculationDetails({calculation}:{calculation:RentalCalculation}) {
  return <details className="rp-price-details"><summary>Voir le détail du calcul · grille #{calculation.version_id}</summary><p>{calculation.rule}</p><ol>{calculation.lines.map((line,index)=><li key={index}><div><strong>{line.label} · {line.half_day_units===1?'½ journée':'1 journée'}</strong><span>{localDate(line.starts_local)} → {localDate(line.ends_local)}</span><small>{money(line.daily_centimes)} / jour</small></div><strong>{new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:3}).format(line.twice_centimes/200)} DH</strong></li>)}</ol></details>;
}

export default function RentalPriceCalculation({calculation,loading,error,frozen,canPrice,disabled,hasQuote,onRefresh,onAdopt,onNegotiate}:{
  calculation:RentalCalculation|null;loading:boolean;error:string;frozen:boolean;canPrice:boolean;disabled:boolean;hasQuote:boolean;
  onRefresh:()=>void;onAdopt:()=>void;onNegotiate:()=>void;
}) {
  return <div className="rp-calculation" aria-label="Calcul du prix de location">
    <div className="rp-section-title"><div><h4><Calculator size={19}/>{frozen?'Calcul conservé avec l’accord':'Prix calculé pour ces dates'} <FieldHelp field="rental_calculation" label="Calcul et prix convenu" hover/></h4><p>{frozen?'Le prix confirmé et son calcul ne changent plus.':'Tarifs de cette voiture, selon les dates du dossier. Hors caution.'}</p></div>{!frozen&&<button type="button" className="secondary" disabled={disabled||loading} onClick={onRefresh}><RefreshCw size={15}/>Actualiser le calcul</button>}</div>
    {loading?<p role="status">Calcul du prix…</p>:error?<div className="rp-notice" role="status"><strong>Calcul indisponible</strong><p>{error}</p><small>Le prix déjà convenu et les paiements sont conservés. Le responsable peut définir un accord manuellement.</small></div>:calculation?<>
      <strong className="rp-calculated-amount">{money(calculation.total_centimes)}</strong>
      <p className="rp-price-explanation">{frozen?'Référence conservée lors de l’accord.':hasQuote?'Ce calcul ne remplace pas le prix déjà convenu ci-dessous.':'Ce montant reste une proposition jusqu’à votre accord avec le client.'}</p>
      <CalculationDetails calculation={calculation}/>
      {canPrice&&!frozen&&<div className="rp-buttons"><button type="button" className="primary" disabled={disabled} onClick={onAdopt}>Utiliser ce prix calculé</button><button type="button" className="secondary" disabled={disabled} onClick={onNegotiate}>Convenir d’un autre prix</button></div>}
    </>:<p>Ce dossier a été confirmé avec un prix saisi manuellement. Aucun détail de calcul n’avait été enregistré.</p>}
    {!canPrice&&!frozen&&<small>Seul le responsable peut convenir du prix avec le client.</small>}
  </div>;
}
