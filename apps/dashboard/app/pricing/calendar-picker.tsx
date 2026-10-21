'use client';

import {useEffect, useRef, useState} from 'react';
import {CalendarDays, ExternalLink, X} from 'lucide-react';
import FieldHelp from '../_components/field-help';
import {calendarOptions, calendarPeriods, calendarReviewedOn, type CalendarPeriod} from './holiday-calendar';

const day = (date: string) => date.split('-').reverse().join('/');
type Existing = {key: string; name: string; starts_on: string; ends_on: string; active: boolean; calendar_id?: string};

export default function CalendarPicker({periods, onChoose, onOpen, onClose}: {
  periods: Existing[];
  onChoose: (period: CalendarPeriod, start: string, end: string) => void;
  onOpen: (key: string) => void;
  onClose: () => void;
}) {
  const [calendar, setCalendar] = useState('school-2026-2027');
  const [selection, setSelection] = useState('');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => {heading.current?.focus();}, []);
  const selected = calendarPeriods.find(period => period.id === selection);
  const existing = selected && periods.find(period => period.calendar_id === selected.id);
  const overlaps = start && end ? periods.filter(period => period.active && period.starts_on <= end && period.ends_on >= start) : [];

  function select(period: CalendarPeriod) {
    setSelection(period.id);
    setStart(period.starts_on);
    setEnd(period.ends_on);
  }

  return <section className="tp-calendar tp-card" aria-labelledby="calendar-title" onKeyDown={event => {if (event.key === 'Escape') {event.stopPropagation(); onClose();}}}>
    <div className="tp-title-row">
      <div><h2 ref={heading} tabIndex={-1} id="calendar-title"><CalendarDays size={20}/>Ajouter depuis le calendrier <FieldHelp field="pricing_calendar" label="Calendrier des vacances et fêtes" hover/></h2><p>1. Choisissez une occasion. 2. Préparez les prix par voiture. 3. Activez après vérification.</p></div>
      <button type="button" className="secondary" onClick={onClose}><X size={16}/>Fermer le calendrier</button>
    </div>
    <label className="tp-calendar-filter">Calendrier à consulter<select value={calendar} onChange={event => {setCalendar(event.target.value); setSelection(''); setStart(''); setEnd('');}}>{calendarOptions.map(option => <option key={option.id} value={option.id}>{option.label}</option>)}</select></label>
    <p className="tp-hint">Références consultées le {calendarReviewedOn}. Les dates scolaires sont celles du calendrier 2026–2027 publié. Les autres années ne sont pas extrapolées. Aucun prix n’est fixé par ces sources.</p>
    <div className="tp-calendar-layout">
      <div className="tp-calendar-list" aria-label="Occasions du calendrier">{calendarPeriods.filter(period => period.calendar === calendar).map(period => {
        const added = periods.some(item => item.calendar_id === period.id);
        return <button type="button" key={period.id} className="tp-calendar-option" aria-pressed={selection === period.id} onClick={() => select(period)}><strong>{period.name}</strong><span>{period.starts_on ? `${day(period.starts_on)} → ${day(period.ends_on)}` : 'Date grégorienne à confirmer'}</span><small>{added ? 'Déjà ajoutée à vos périodes' : period.category === 'school' ? 'Dates publiées · 8 jours inclus' : period.category === 'national' ? 'Date publiée' : 'Repère lunaire · sans date civile préremplie'}</small></button>;
      })}</div>
      <div className="tp-calendar-detail">{selected ? <>
        <span className="tp-eyebrow">OCCASION CHOISIE</span><h3>{selected.name}</h3>
        <p>{selected.reference}</p>
        <a className="tp-source-link" href={selected.source} target="_blank" rel="noopener noreferrer"><ExternalLink size={15}/>Voir la source officielle</a><small>{selected.sourceLabel}</small>
        {existing ? <div className="tp-hint"><p>Cette occasion existe déjà : <strong>{existing.name}</strong>. Ouvrez-la pour modifier ses dates ou ses prix.</p><button type="button" className="secondary" onClick={() => onOpen(existing.key)}>Ouvrir la période existante</button></div> : <>
          {selected.category === 'religious' && <p className="tp-warning">Le repère religieux est officiel ; sa date grégorienne n’est pas confirmée ici. Choisissez vos dates commerciales ci-dessous, après vérification de l’annonce marocaine. Elles ne seront pas présentées comme des dates officielles de l’Aïd.</p>}
          <div className="tp-form-grid"><label>Début de votre période tarifaire<input type="date" value={start} onChange={event => setStart(event.target.value)}/></label><label>Fin de votre période tarifaire<input type="date" value={end} min={start || undefined} onChange={event => setEnd(event.target.value)}/></label></div>
          <p>Vous pouvez élargir la période avant ou après l’occasion. Les deux dates sont incluses.</p>
          {!!overlaps.length && <div className="tp-warning"><strong>Chevauchement de dates à vérifier</strong>{overlaps.map(period => <p key={period.key}>{period.name} · {day(period.starts_on)} → {day(period.ends_on)}</p>)}<p>Possible pour des voitures différentes. Pour une même voiture, résolvez le conflit avant activation.</p></div>}
          {start && end && end < start && <p role="alert" className="tp-error">La fin doit être le même jour que le début ou après.</p>}
          <button type="button" className="primary" disabled={!start || !end || end < start} onClick={() => onChoose(selected, start, end)}>Préparer les prix par voiture</button>
          <p><strong>Ajout en brouillon.</strong> Aucune voiture sélectionnée, aucun tarif activé automatiquement.</p>
        </>}
      </> : <div className="tp-calendar-empty"><CalendarDays size={30}/><h3>Choisissez une occasion dans la liste</h3><p>Ses dates et sa source apparaîtront ici. Vous gardez le choix des voitures, des prix et de la durée de votre période.</p></div>}</div>
    </div>
  </section>;
}
