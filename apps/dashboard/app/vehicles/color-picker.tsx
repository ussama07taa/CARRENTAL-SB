'use client';
import FieldHelp from '../_components/field-help';

import { useId, useState } from 'react';

const colors = [
  ['Blanc', '#f5f5f0'], ['Noir', '#202225'], ['Gris', '#858b90'],
  ['Argent', 'linear-gradient(135deg, #a6adb4, #edf0f2, #9099a2)'],
  ['Bleu', '#285c9b'], ['Rouge', '#b83239'], ['Vert', '#396c55'],
  ['Beige', '#cdbb99'], ['Marron', '#795646'], ['Bordeaux', '#712b40'],
  ['Orange', '#d87935'], ['Jaune', '#e8c54c'],
] as const;

export default function ColorPicker({ value, onChange, errors = [] }: {
  value: string; onChange: (color: string) => void; errors?: string[];
}) {
  const id = useId();
  const [custom, setCustom] = useState(false);
  const known = colors.some(([name]) => name === value);
  const showCustom = custom || (value !== '' && !known);

  return <fieldset className="v-color-picker v-full">
    <legend>Couleur de la carrosserie<FieldHelp field="color" label="Couleur de la carrosserie"/></legend>
    <div className="v-color-summary">
      <span>Couleur sélectionnée : <strong aria-live="polite">{value || 'Non renseignée'}</strong></span>
      {(value || custom) && <button type="button" className="text-button" onClick={() => { setCustom(false); onChange(''); }}>Effacer</button>}
    </div>
    <div className="v-color-options">
      {colors.map(([name, swatch]) => <label className={'v-color-option ' + (value === name && !custom ? 'selected' : '')} key={name}>
        <input aria-label={name} type="radio" name={id} value={name} checked={value === name && !custom} onChange={() => { setCustom(false); onChange(name); }} />
        <span className="v-color-swatch" style={{ background: swatch }} aria-hidden="true"><span>✓</span></span>
        <span>{name}</span>
      </label>)}
      <label className={'v-color-option v-color-other ' + (showCustom ? 'selected' : '')}>
        <input aria-label="Autre couleur" type="radio" name={id} checked={showCustom} onChange={() => { setCustom(true); if (known) onChange(''); }} />
        <span className="v-color-swatch" aria-hidden="true">＋</span><span>Autre</span>
      </label>
    </div>
    {showCustom && <label className="v-field v-custom-color" htmlFor={id + '-custom'}>
      <span>Nom de la couleur<FieldHelp field={"color"} label={"Nom de la couleur"}/></span>
      <input aria-label={"Nom de la couleur"} id={id + '-custom'} name="color" value={value} maxLength={40} placeholder="Ex. Bleu nuit, blanc nacré…" onChange={e => onChange(e.target.value)} aria-invalid={errors.length > 0} aria-describedby={errors.length?id+'-error':undefined} />
    </label>}
    {errors.map(error => <p id={id+'-error'} className="v-error" role="alert" key={error}>{error}</p>)}
  </fieldset>;
}
