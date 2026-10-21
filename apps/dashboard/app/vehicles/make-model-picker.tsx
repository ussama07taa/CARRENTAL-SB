'use client';
import FieldHelp from '../_components/field-help';

import { useId, useState } from 'react';
import { vehicleCatalog } from './vehicle-catalog';

const other = '__other__';
const brands = Object.keys(vehicleCatalog).sort((a, b) => a.localeCompare(b, 'fr'));

export default function MakeModelPicker({ make, model, onChange, errors }: {
  make: string; model: string;
  onChange: (make: string, model: string) => void;
  errors: Record<string, string[]>;
}) {
  const id = useId();
  const [customMake, setCustomMake] = useState(false);
  const [customModel, setCustomModel] = useState(false);
  const knownMake = Object.hasOwn(vehicleCatalog, make);
  const models = knownMake ? vehicleCatalog[make] : [];
  const showMakeInput = customMake || (make !== '' && !knownMake);
  const showModelInput = customModel || (model !== '' && !models.includes(model)) || (make !== '' && !knownMake);

  function chooseMake(value: string) {
    setCustomMake(value === other);
    setCustomModel(false);
    onChange(value === other ? '' : value, '');
  }

  return <>
    <div className="v-field">
      <label htmlFor={id + '-make'}>Marque <b aria-label="obligatoire">*</b><FieldHelp field="make_choice" label="Marque"/></label>
      <select aria-label="Marque" id={id + '-make'} name="make_choice" required value={showMakeInput ? other : make} onChange={e => chooseMake(e.target.value)} aria-invalid={!!errors.make?.length} aria-describedby={errors.make?.length?id+'-make-error':undefined}>
        <option value="">Choisir une marque</option>
        {brands.map(brand => <option key={brand} value={brand}>{brand}</option>)}
        <option value={other}>Autre marque…</option>
      </select>
      {showMakeInput && <label className="v-field v-catalog-custom">
        <span>Nom de la marque *<FieldHelp field={"make"} label={"Nom de la marque"}/></span>
        <input aria-label={"Nom de la marque"} name="make" aria-invalid={!!errors.make?.length} aria-describedby={errors.make?.length?id+'-make-error':undefined} value={make} required maxLength={60} placeholder="Ex. une marque absente de la liste" onChange={e => { setCustomModel(false); onChange(e.target.value, ''); }} />
      </label>}
      {errors.make?.map(error => <small id={id+'-make-error'} className="v-error" key={error}>{error}</small>)}
    </div>
    <div className="v-field">
      <label htmlFor={id + '-model'}>Modèle <b aria-label="obligatoire">*</b><FieldHelp field="model_choice" label="Modèle"/></label>
      <select aria-label="Modèle" id={id + '-model'} name="model_choice" required disabled={!make.trim()} value={showModelInput ? other : model} onChange={e => { setCustomModel(e.target.value === other); onChange(make, e.target.value === other ? '' : e.target.value); }} aria-invalid={!!errors.model?.length} aria-describedby={errors.model?.length?id+'-model-error':undefined}>
        <option value="">{make.trim() ? 'Choisir un modèle' : 'Choisir la marque d’abord'}</option>
        {models.map(name => <option key={name} value={name}>{name}</option>)}
        <option value={other}>Autre modèle…</option>
      </select>
      {showModelInput && <label className="v-field v-catalog-custom">
        <span>Nom du modèle *<FieldHelp field={"model"} label={"Nom du modèle"}/></span>
        <input aria-label={"Nom du modèle"} name="model" aria-invalid={!!errors.model?.length} aria-describedby={errors.model?.length?id+'-model-error':undefined} value={model} required disabled={!make.trim()} maxLength={80} placeholder="Nom du modèle" onChange={e => onChange(make, e.target.value)} />
      </label>}
      {errors.model?.map(error => <small id={id+'-model-error'} className="v-error" key={error}>{error}</small>)}
    </div>
  </>;
}
