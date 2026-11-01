'use client';
import {LayoutDashboard,CarFront,FileCheck2,Wrench,Landmark,ArrowUpRight,Camera,Pencil,Gauge} from 'lucide-react';
import './vehicle-file-layout.css';
import DamagePanel from '../incidents/damage-panel';

import {useState} from 'react';
import type {DocumentOverview} from './vehicle-documents';
import { vehicleTypes } from './vehicle-types';
import VehicleDocuments from './vehicle-documents';
import VehicleMaintenance from './vehicle-maintenance';
import VehiclePricingCard from './vehicle-pricing';
import PhotoTile from '../clients/document-upload';
import type { Vehicle, Photo, Reading, Slot } from './vehicles';

export default function VehicleSummary({ vehicle, photos, readings, equipment, slots, justCreated = false, onChange, onPricingDirty, onPricingBusy, onPricingSaved, onEdit, editDisabled }: {
  editDisabled:boolean;
  onEdit:(section:'info'|'equipment'|'photos'|'odometer')=>void;
  onPricingDirty:(dirty:boolean)=>void; onPricingBusy:(busy:boolean)=>void;
  onPricingSaved:()=>void;
  onChange:()=>void; justCreated?: boolean; vehicle: Vehicle; photos: Photo[]; readings: Reading[];
  equipment: readonly (readonly [string, string])[];
  slots: readonly (readonly [Slot, string])[];
}) {
  const [active,setActive]=useState(justCreated?'documents':'overview');
  const views=[['overview','Vue d’ensemble',LayoutDashboard],['information','Informations et photos',CarFront],['documents','Documents',FileCheck2],['maintenance','Entretien',Wrench],['finance','Achat et échéances',Landmark]] as const;
  const [documentOverview,setDocumentOverview]=useState<DocumentOverview|null>(null);
  const overview=documentOverview?.vehicleId===vehicle.id?documentOverview:null;
  const fuels: Record<string, string> = { diesel: 'Diesel', petrol: 'Essence', hybrid: 'Hybride', electric: 'Électrique' };
  const gears: Record<string, string> = { manual: 'Manuelle', automatic: 'Automatique' };
  const formatKm = (value: number | null) => value === null ? 'Non renseigné' : new Intl.NumberFormat('fr-MA').format(value) + ' km';
  const date = (value: string) => new Intl.DateTimeFormat('fr-MA', { dateStyle: 'medium' }).format(new Date(value + 'T12:00:00'));
  const cover = photos.find(photo => photo.slot === 'cover');
  const additional = photos.filter(photo => photo.slot !== 'cover');
  const specifications = [
    ['Type', vehicleTypes[vehicle.vehicle_type]], ['Marque', vehicle.make], ['Modèle', vehicle.model], ['Finition', vehicle.trim],
    ['Année', vehicle.year], ['Carburant', fuels[vehicle.fuel]], ['Boîte de vitesses', gears[vehicle.transmission]],
    ['Couleur', vehicle.color], ['Places (chauffeur inclus)', vehicle.seats], ['Portes', vehicle.doors], ['Numéro de châssis', vehicle.vin],
  ];
  function photoTile(photo: Photo) {
    return <PhotoTile<Slot> key={photo.slot} slot={photo.slot} label={slots.find(([slot]) => slot === photo.slot)?.[1] ?? photo.slot}
      collection="vehicles" customerId={vehicle.id} record={photo} editable={false} disabled={false} onSelect={() => {}} onRemove={() => {}} />;
  }
  return <div className="vehicle-summary v-file-workspace">
    <div className="v-summary-lead">
      <div className="v-summary-cover">
        {cover ? photoTile(cover) : <div className="v-summary-no-photo"><svg width="120" height="64" viewBox="0 0 80 40" fill="none" stroke="currentColor" strokeWidth="1.2" aria-hidden="true"><path d="m8 29 4-12 15-3 9-8h19l11 11 8 4v9H8Zm21-15h31L53 8H38l-9 6Z"/><circle cx="22" cy="30" r="6" fill="#26333c"/><circle cx="60" cy="30" r="6" fill="#26333c"/></svg><strong>Pas encore de photo</strong><span>Ajoutez-la depuis « Modifier le fichier ».</span></div>}
      </div>
      <div className="v-summary-identity"><span className="v-summary-eyebrow">IMMATRICULATION</span><strong className="v-summary-plate">{vehicle.plate}</strong><p>{[vehicle.year, fuels[vehicle.fuel], gears[vehicle.transmission]].filter(Boolean).join(' · ') || 'Caractéristiques à renseigner'}</p>
        <div className="v-summary-meter"><span>Dernier compteur</span><strong>{formatKm(vehicle.current_odometer)}</strong><small>{vehicle.current_odometer_date ? 'Relevé du ' + date(vehicle.current_odometer_date) : 'Aucun relevé enregistré'}</small><button type="button" className="v-file-inline" disabled={editDisabled} onClick={()=>onEdit('odometer')}><Gauge size={16}/> Ajouter un relevé</button></div>
      </div>
    </div>

    <div className="v-file-tabs" role="tablist" aria-label="Rubriques du fichier véhicule">{views.map(([key,label,Icon],index)=><button type="button" role="tab" id={'vf-tab-'+key} aria-controls={'vf-panel-'+key} aria-selected={active===key} tabIndex={active===key?0:-1} key={key} onClick={()=>setActive(key)} onKeyDown={event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?views.length-1:(index+(event.key==='ArrowRight'?1:views.length-1))%views.length;setActive(views[next][0]);document.getElementById('vf-tab-'+views[next][0])?.focus();}}><Icon size={18}/><span>{label}</span>{key==='documents'&&!!overview?.attention&&<b>{overview.attention}</b>}</button>)}</div>
    <section className="v-workspace-panel" id="vf-panel-overview" role="tabpanel" aria-labelledby="vf-tab-overview" hidden={active!=='overview'}>
      <div className="v-file-overview-grid"><VehiclePricingCard vehicleId={vehicle.id} onDirtyChange={onPricingDirty} onBusyChange={onPricingBusy} onSaved={onPricingSaved}/>
      <section className={'v-file-health '+(overview?.blocked?'blocked':overview?.attention?'attention':'neutral')} aria-label="État des documents du véhicule"><FileCheck2 size={22}/><h3>Documents et échéances</h3><strong>{!overview?'Vérification des documents…':overview.blocked?`${overview.blocked} document(s) bloquant(s)`:overview.attention?`${overview.attention} document(s) à suivre`:'Documents contrôlés pour aujourd’hui'}</strong><p>{overview?.blocked?overview.labels.join(' · '):'Le départ reste soumis au contrôle de disponibilité, de la voiture et du dossier client.'}</p><button type="button" className="secondary" onClick={()=>setActive('documents')}>Consulter les documents <ArrowUpRight size={16}/></button></section></div>
      <div className="v-file-damage"><DamagePanel vehicleId={vehicle.id}/></div>
    </section>
    {editDisabled&&<p role="status">Terminez ou annulez la modification du prix avant de modifier les informations du véhicule.</p>}
    <section className="v-workspace-panel" id="vf-panel-information" role="tabpanel" aria-labelledby="vf-tab-information" hidden={active!=='information'}>
      <div className="v-file-section-heading"><div><h3>Informations et photos</h3><p>Les caractéristiques et les visuels enregistrés pour cette voiture.</p></div><div><button type="button" className="secondary" disabled={editDisabled} onClick={()=>onEdit('info')}><Pencil size={16}/> Modifier</button><button type="button" className="secondary" disabled={editDisabled} onClick={()=>onEdit('photos')}><Camera size={16}/> Gérer les photos</button></div></div>
<section className="v-summary-card" aria-labelledby="v-summary-specs"><h3 id="v-summary-specs">La voiture en un coup d’œil</h3><dl className="v-summary-specs v-file-specs">{specifications.map(([label, value]) => <div key={label}><dt>{label}</dt><dd className={value == null || value === '' ? 'missing' : undefined}>{value == null || value === '' ? 'Non renseigné' : value}</dd></div>)}</dl></section>
    <section className="v-summary-card" aria-labelledby="v-summary-equipment"><h3 id="v-summary-equipment">Équipements <span>{vehicle.equipment.length}</span></h3>{vehicle.equipment.length ? <ul className="v-summary-equipment">{vehicle.equipment.map(key => <li key={key}><span aria-hidden="true">✓</span>{equipment.find(([id]) => id === key)?.[1] ?? key}</li>)}</ul> : <p>Aucun équipement renseigné.</p>}</section>


    {!!vehicle.custom_equipment?.length&&<section className="v-summary-card"><h3>Équipements supplémentaires</h3><ul className="v-summary-equipment">{vehicle.custom_equipment.map((item,index)=><li key={index}>{item.fr}</li>)}</ul></section>}
    {additional.length > 0 && <section className="v-summary-card"><h3>Autres photos ({additional.length})</h3><div className="v-photo-grid">{additional.map(photoTile)}</div></section>}
    <details className="v-summary-more"><summary>Historique du compteur <span>{readings.length ? 'Les derniers relevés' : 'Aucun relevé'}</span></summary><div className="v-history">{readings.length ? <ol>{readings.map(reading => <li key={reading.id}><span className={'v-reading-dot ' + (reading.kind === 'correction' ? 'correction' : '')}/><div><strong>{formatKm(reading.kilometers)}</strong><p>{date(reading.recorded_on)} · {reading.actor}</p>{reading.reason && <p>{reading.reason}</p>}</div><span className="v-reading-kind">{reading.kind === 'initial' ? 'Initial' : reading.kind === 'correction' ? 'Correction' : 'Relevé'}</span></li>)}</ol> : <p>Aucun relevé enregistré.</p>}</div></details>

    </section>
    <section className="v-workspace-panel" id="vf-panel-documents" role="tabpanel" aria-labelledby="vf-tab-documents" hidden={active!=='documents'}>
      <div className="v-file-section-heading"><div><h3>Assurance et documents</h3><p>Pièces enregistrées, dates de validité et documents à compléter.</p></div></div>
      {justCreated&&<p role="status">Votre véhicule est enregistré. Vous pouvez maintenant ajouter son assurance et ses documents.</p>}
      <VehicleDocuments vehicleId={vehicle.id} onOverview={setDocumentOverview}/>
    </section>
    <section className="v-workspace-panel" id="vf-panel-maintenance" role="tabpanel" aria-labelledby="vf-tab-maintenance" hidden={active!=='maintenance'}><VehicleMaintenance vehicleId={vehicle.id} onChange={onChange}/></section>
    <section className="v-workspace-panel" id="vf-panel-finance" role="tabpanel" aria-labelledby="vf-tab-finance" hidden={active!=='finance'}><div className="v-file-finance"><Landmark size={28}/><h3>Achat et échéances</h3><p>Retrouvez le dossier d’achat et les échéances associés à cette voiture dans l’espace dédié.</p><a className="secondary" href={`/loans?vehicle=${vehicle.id}`}>Ouvrir le dossier d’achat <ArrowUpRight size={16}/></a></div></section>
  </div>;
}
