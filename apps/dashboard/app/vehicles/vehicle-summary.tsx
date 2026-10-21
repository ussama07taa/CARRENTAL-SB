'use client';
import DamagePanel from '../incidents/damage-panel';

import {useState} from 'react';
import type {DocumentOverview} from './vehicle-documents';
import { vehicleTypes } from './vehicle-types';
import VehicleDocuments from './vehicle-documents';
import VehicleMaintenance from './vehicle-maintenance';
import VehiclePricingCard from './vehicle-pricing';
import PhotoTile from '../clients/document-upload';
import type { Vehicle, Photo, Reading, Slot } from './vehicles';

export default function VehicleSummary({ vehicle, photos, readings, equipment, slots, justCreated = false, onChange, onPricingDirty, onPricingBusy, onPricingSaved }: {
  onPricingDirty:(dirty:boolean)=>void; onPricingBusy:(busy:boolean)=>void;
  onPricingSaved:()=>void;
  onChange:()=>void; justCreated?: boolean; vehicle: Vehicle; photos: Photo[]; readings: Reading[];
  equipment: readonly (readonly [string, string])[];
  slots: readonly (readonly [Slot, string])[];
}) {
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
  return <div className="vehicle-summary"><DamagePanel vehicleId={vehicle.id}/>
    <section className={'v-file-health '+(overview?.blocked?'blocked':overview?.attention?'attention':'neutral')} aria-label="État des documents du véhicule"><strong>{!overview?'Vérification des documents…':overview.blocked?`${overview.blocked} document(s) bloquent la remise des clés`:overview.attention?`${overview.attention} document(s) à compléter ou à suivre`:'Documents contrôlés pour aujourd’hui'}</strong><p>{!overview?'Consultez le détail ci-dessous si le chargement ne se termine pas.':overview.blocked?overview.labels.join(' · '):'Le fichier enregistré ne signifie pas que la voiture est prête : le départ contrôle aussi sa disponibilité, son état et le dossier client.'}</p><button type="button" className="secondary" onClick={()=>{const node=document.getElementById('v-doc-title');node?.scrollIntoView({block:'start',behavior:'smooth'});node?.focus({preventScroll:true});}}>Voir les documents à traiter ↓</button></section>
    {justCreated&&<div className="v-created-next" role="status"><div><strong>Votre véhicule est enregistré.</strong><p>Ajoutez maintenant son assurance et ses documents, ou complétez-les plus tard.</p></div><button type="button" className="v-doc-action" onClick={()=>{const target=document.getElementById('v-doc-title');target?.scrollIntoView({block:'start',behavior:'smooth'});target?.focus({preventScroll:true});}}>Ajouter l’assurance et les documents ↓</button></div>}
    <nav className="v-file-shortcuts" aria-label="Accès rapide au fichier"><button type="button" onClick={()=>document.getElementById('v-doc-title')?.scrollIntoView({block:'start',behavior:'smooth'})}>Documents & échéances ↓</button><button type="button" onClick={()=>document.getElementById('maintenance-title')?.scrollIntoView({block:'start',behavior:'smooth'})}>Entretien & réparations ↓</button><a href={`/loans?vehicle=${vehicle.id}`}>Crédits & échéances ↗</a></nav>
    <div className="v-summary-lead">
      <div className="v-summary-cover">
        {cover ? photoTile(cover) : <div className="v-summary-no-photo"><svg width="120" height="64" viewBox="0 0 80 40" fill="none" stroke="currentColor" strokeWidth="1.2" aria-hidden="true"><path d="m8 29 4-12 15-3 9-8h19l11 11 8 4v9H8Zm21-15h31L53 8H38l-9 6Z"/><circle cx="22" cy="30" r="6" fill="#26333c"/><circle cx="60" cy="30" r="6" fill="#26333c"/></svg><strong>Pas encore de photo</strong><span>Ajoutez-la depuis « Modifier le fichier ».</span></div>}
      </div>
      <div className="v-summary-identity"><span className="v-summary-eyebrow">IMMATRICULATION</span><strong className="v-summary-plate">{vehicle.plate}</strong><p>{[vehicle.year, fuels[vehicle.fuel], gears[vehicle.transmission]].filter(Boolean).join(' · ') || 'Caractéristiques à renseigner'}</p>
        <div className="v-summary-meter"><span>Dernier compteur</span><strong>{formatKm(vehicle.current_odometer)}</strong><small>{vehicle.current_odometer_date ? 'Relevé du ' + date(vehicle.current_odometer_date) : 'Aucun relevé enregistré'}</small></div>
      </div>
    </div>
    <VehiclePricingCard vehicleId={vehicle.id} onDirtyChange={onPricingDirty} onBusyChange={onPricingBusy} onSaved={onPricingSaved}/>
    <VehicleDocuments vehicleId={vehicle.id} onOverview={setDocumentOverview}/>
    <VehicleMaintenance vehicleId={vehicle.id} onChange={onChange}/>
    <section className="v-summary-card" aria-labelledby="v-summary-specs"><h3 id="v-summary-specs">La voiture en un coup d’œil</h3><dl className="v-summary-specs">{specifications.map(([label, value]) => <div key={label}><dt>{label}</dt><dd className={value == null || value === '' ? 'missing' : undefined}>{value == null || value === '' ? 'Non renseigné' : value}</dd></div>)}</dl></section>
    <section className="v-summary-card" aria-labelledby="v-summary-equipment"><h3 id="v-summary-equipment">Équipements <span>{vehicle.equipment.length}</span></h3>{vehicle.equipment.length ? <ul className="v-summary-equipment">{vehicle.equipment.map(key => <li key={key}><span aria-hidden="true">✓</span>{equipment.find(([id]) => id === key)?.[1] ?? key}</li>)}</ul> : <p>Aucun équipement renseigné.</p>}</section>
    {additional.length > 0 && <details className="v-summary-more"><summary>Autres photos <span>{additional.length}</span></summary><div className="v-photo-grid">{additional.map(photoTile)}</div></details>}
    <details className="v-summary-more"><summary>Historique du compteur <span>{readings.length ? 'Les derniers relevés' : 'Aucun relevé'}</span></summary><div className="v-history">{readings.length ? <ol>{readings.map(reading => <li key={reading.id}><span className={'v-reading-dot ' + (reading.kind === 'correction' ? 'correction' : '')}/><div><strong>{formatKm(reading.kilometers)}</strong><p>{date(reading.recorded_on)} · {reading.actor}</p>{reading.reason && <p>{reading.reason}</p>}</div><span className="v-reading-kind">{reading.kind === 'initial' ? 'Initial' : reading.kind === 'correction' ? 'Correction' : 'Relevé'}</span></li>)}</ol> : <p>Aucun relevé enregistré.</p>}</div></details>
  </div>;
}
