'use client';
import {useState} from 'react';
import {type Point,money,shortMoney,date,monthLabel} from './report-types';

export default function ReportChart({daily,trend,onMonth}:{daily:Point[];trend:Point[];onMonth:(month:string)=>void}){
 const [mode,setMode]=useState<'days'|'months'>('days'),[series,setSeries]=useState('all'),[active,setActive]=useState<number|null>(null);
 const rows=mode==='days'?daily:trend;
 const incoming=series!=='out',outgoing=series!=='in';
 const max=Math.max(100,...rows.flatMap(p=>[incoming?p.incoming_centimes:0,outgoing?p.outgoing_centimes:0]));
 const scale=Math.pow(10,Math.floor(Math.log10(max)));
 const top=Math.ceil(max/scale)*scale;
 const W=760,H=248,left=56,right=14,bottom=26,plot=H-bottom-14,step=(W-left-right)/Math.max(1,rows.length);
 const chosen=rows[Math.min(active??rows.length-1,rows.length-1)];
 const label=(r:Point)=>r.month?monthLabel(r.month):date(r.date!);
 const select=(i:number)=>setActive(i);
 return <section className="rp-panel rp-chart" aria-labelledby="report-chart-title">
  <div className="rp-panel-head"><div><span className="rp-kicker">LES MOUVEMENTS RÉELS</span><h2 id="report-chart-title">L’argent, dans le temps</h2></div><div className="rp-segment" aria-label="Période du graphique">{[['days','Ce mois'],['months','6 mois']].map(([v,l])=><button key={v} type="button" aria-pressed={mode===v} onClick={()=>{setMode(v as 'days'|'months');setActive(null);}}>{l}</button>)}</div></div>
  <div className="rp-chart-tools"><div className="rp-legend"><span><i className="rp-dot blue"/>Entrées</span><span><i className="rp-dot orange"/>Sorties</span></div><label>Afficher <select data-validation-field="series" value={series} onChange={e=>{setSeries(e.target.value);setActive(null);}}><option value="all">Entrées et sorties</option><option value="in">Entrées seules</option><option value="out">Sorties seules</option></select></label></div>
  <div className="rp-chart-readout" aria-live="polite"><span>{chosen?label(chosen):'Aucun mouvement'}</span><strong className="rp-in">+ {money(chosen?.incoming_centimes??0)}</strong><strong className="rp-out">− {money(chosen?.outgoing_centimes??0)}</strong>{chosen?.month&&<button className="rp-text" onClick={()=>onMonth(chosen.month!)}>Ouvrir ce mois ↗</button>}</div>
  <svg className="rp-plot" viewBox={'0 0 '+W+' '+H} role="group" aria-label="Graphique des entrées en bleu et sorties en orange, en dirhams. Utilisez Tab pour lire les valeurs.">
   {[0,1,2,3,4].map(i=>{const value=top*i/4,y=H-bottom-plot*i/4;return <g key={i}><line x1={left} x2={W-right} y1={y} y2={y} stroke="#e8edf5" strokeDasharray={i?'3 5':undefined}/><text x={left-9} y={y+4} textAnchor="end" fill="#64748b" fontSize="11">{shortMoney(value)}</text></g>;})}
   {rows.map((r,i)=>{const x=left+i*step,w=Math.min(mode==='months'?29:9,step*.28),base=H-bottom,ih=r.incoming_centimes/top*plot,oh=r.outgoing_centimes/top*plot;return <g key={r.date??r.month} role="button" tabIndex={0} aria-label={label(r)+', entrées '+money(r.incoming_centimes)+', sorties '+money(r.outgoing_centimes)} onFocus={()=>select(i)} onPointerEnter={()=>select(i)} onClick={()=>select(i)} onKeyDown={e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();select(i);}if(e.key==='ArrowRight'||e.key==='ArrowLeft'){e.preventDefault();const next=e.key==='ArrowRight'?e.currentTarget.nextElementSibling:e.currentTarget.previousElementSibling;if(next?.getAttribute('role')==='button')(next as SVGElement).focus();}}}>
    <rect x={x+1} y={8} width={Math.max(1,step-2)} height={plot+12} fill={active===i?'#edf3ff':'transparent'} rx={5}/>
    {incoming&&<rect className="rp-bar" x={x+step/2-w-1} y={base-ih} width={w} height={ih} rx={Math.min(3,ih/2)} fill="#004fff"/>}
    {outgoing&&<rect className="rp-bar" x={x+step/2+1} y={base-oh} width={w} height={oh} rx={Math.min(3,oh/2)} fill="#f29347"/>}
    {(mode==='months'||i%4===0||i===rows.length-1)&&<text x={x+step/2} y={H-5} textAnchor="middle" fill="#64748b" fontSize="11">{r.month?new Intl.DateTimeFormat('fr-FR',{month:'short'}).format(new Date(r.month+'-01T12:00:00')):String(Number(r.date!.slice(-2)))}</text>}
   </g>;})}
  </svg>
  {rows.every(r=>(!incoming||r.incoming_centimes===0)&&(!outgoing||r.outgoing_centimes===0))&&<p className="rp-chart-empty">Aucun {series==='in'?'encaissement':series==='out'?'paiement sortant':'mouvement'} sur cette période.</p>}
  <p className="rp-caption">{mode==='days'?'Touchez une journée pour lire ses montants.':'Chaque barre représente les paiements du mois. Le mois en cours reste partiel.'} Même échelle, en DH.</p>
  <details className="rp-data-table"><summary>Lire les chiffres du graphique</summary><div className="rp-table-scroll"><table><thead><tr><th>Période</th><th>Entrées</th><th>Sorties</th><th>Différence</th></tr></thead><tbody>{rows.map(r=><tr key={r.date??r.month}><th>{label(r)}</th><td>{money(r.incoming_centimes)}</td><td>{money(r.outgoing_centimes)}</td><td>{money(r.incoming_centimes-r.outgoing_centimes)}</td></tr>)}</tbody></table></div></details>
 </section>;
}
