import {ClipboardList, ChevronRight} from 'lucide-react';
import './coverage.css';

type Vehicle={id:number;plate:string;make:string;model:string};
export type MaintenanceCoverage={vehicle_count:number;without_history:Vehicle[];without_reminder:Vehicle[]};
export default function Coverage({data}:{data:MaintenanceCoverage}){
 const missing=data.without_history.length+data.without_reminder.length;
 return <section className="mo-coverage" aria-label="Informations de suivi du parc">
  <div className="mo-coverage-heading"><ClipboardList size={21}/><div><h2>{missing?'Des informations de suivi restent à compléter':'Informations de suivi enregistrées'}</h2><p>Sur l’ensemble du parc · {data.vehicle_count} voiture(s). Les alertes reposent sur les entretiens et les échéances renseignés ; leur absence ne confirme pas l’état de la voiture.</p></div></div>
  {missing>0&&<div className="mo-coverage-groups">{[{title:'Sans historique d’entretien actif',rows:data.without_history,help:'Aucun dossier non annulé enregistré. Ajoutez les interventions connues depuis « Ajouter un entretien ».'},{title:'Historique présent, sans prochaine échéance',rows:data.without_reminder,help:'Vérifiez si un prochain entretien doit être prévu. Une date ou un kilométrage peuvent être renseignés dans le travail terminé.'}].filter(g=>g.rows.length>0).map(group=><details key={group.title}><summary><span>{group.title}</span><strong>{group.rows.length}</strong></summary><p>{group.help}</p><ul>{group.rows.map(v=><li key={v.id}><a href={'/vehicles?vehicle='+v.id}><span><strong>{v.make} {v.model}</strong><small>{v.plate}</small></span><ChevronRight size={16}/><span className="sr-only">Ouvrir la fiche voiture</span></a></li>)}</ul></details>)}</div>}
 </section>;
}
