'use client';
import FieldHelp from '../_components/field-help';

import { useId, useState } from 'react';

export default function SeatsPicker({ value, onChange, errors = [] }: {
  value: string; onChange: (value: string) => void; errors?: string[];
}) {
  const id = useId();
  const [custom, setCustom] = useState(false);
  const showCustom = custom || Number(value) > 9;
  return <div className="v-field">
    <label id={id + '-label'} htmlFor={id}>Nombre de places<FieldHelp field={"seats"} label={"Nombre de places"}/></label>
    <select id={id} name="seats_choice" aria-invalid={errors.length>0} aria-label="Nombre de places" aria-describedby={id+'-help'+(errors.length?' '+id+'-error':'')} value={showCustom ? 'other' : value}
      onChange={e => { setCustom(e.target.value === 'other'); onChange(e.target.value === 'other' ? '' : e.target.value); }}>
      <option value="">À renseigner</option>
      {Array.from({ length: 9 }, (_, i) => <option key={i + 1} value={String(i + 1)}>{i + 1} place{i > 0 ? 's' : ''}</option>)}
      <option value="other">Autre nombre…</option>
    </select>
    <small id={id + '-help'} className="v-seats-help">Chauffeur inclus dans le nombre total.</small>
    {showCustom && <label className="v-field v-catalog-custom">
      <span>Nombre total de places *<FieldHelp field={"seats"} label={"Nombre total de places"}/></span>
      <input aria-label={"Nombre total de places"} name="seats" type="number" inputMode="numeric" min="10" max="99" step="1" required value={value}
        placeholder="Ex. 12 ou 17" aria-describedby={id+'-help'+(errors.length?' '+id+'-error':'')} aria-invalid={errors.length > 0} onChange={e => onChange(e.target.value)} />
    </label>}
    {errors.map(error => <small id={id+'-error'} className="v-error" role="alert" key={error}>{error}</small>)}
  </div>;
}
