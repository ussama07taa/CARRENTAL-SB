// Design fixtures only. Never use these identifiers against a business API.
export const contexts = [
  {id:'example-rental', label:'Location EXEMPLE-01 · Renault Clio', car:'Renault Clio', plate:'EXEMPLE-A', customer:'Client fictif A', rental:'EXEMPLE-01'},
  {id:'example-outside', label:'Hors location · Dacia Sandero', car:'Dacia Sandero', plate:'EXEMPLE-B', customer:null, rental:null},
] as const;
export type ContextId = typeof contexts[number]['id'];
export type Incident = {
  id:string; context:ContextId; title:string; date:string; place:string; description:string;
  mobility:'unknown'|'blocked'|'ready'; evidence:string[];
  requested:number|null; approved:number|null; received:number; customerDue:number|null;
};
export const initialIncidents:Incident[] = [
  {id:'EX-SIN-01',context:'example-rental',title:'Choc à l’aile avant droite',date:'2026-10-20T14:30',place:'Casablanca',description:'Dommage signalé au retour. Les circonstances et les pièces restent à vérifier.',mobility:'blocked',evidence:[],requested:null,approved:null,received:0,customerDue:null},
  {id:'EX-SIN-02',context:'example-outside',title:'Réparation terminée · assurance à suivre',date:'2026-10-15T10:00',place:'Casablanca',description:'Exemple de véhicule prêt avec une indemnisation encore en cours.',mobility:'ready',evidence:[],requested:800000,approved:600000,received:200000,customerDue:null},
];
export const money = (value:number|null) => value === null ? 'À déterminer' : new Intl.NumberFormat('fr-MA',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100)+' DH';
export const mobilityLabels = {unknown:'À vérifier', blocked:'Immobilisée', ready:'Prête après contrôle'};
export const notes = ['Dommage signalé au retour. Les circonstances et les pièces restent à vérifier.','Incident signalé pendant la location. Contacter le client pour compléter les faits.','Dommage constaté hors location. Photos et circonstances à compléter.'];
