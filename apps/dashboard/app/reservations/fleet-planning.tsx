'use client';

import {useEffect, useState, type CSSProperties} from 'react';
import {ArrowUpRight, CalendarDays, CarFront, Check, ChevronLeft, ChevronRight, Clock3, FileText, Info, Plus, RefreshCw, Search, Wrench, X} from 'lucide-react';
import {api} from '../_lib/auth-client';
import {type FleetPreview} from '../_components/fleet-spotlight';
import MaintenanceFile from '../vehicles/maintenance-file';
import {type RequestSeed} from './request-form';
import './fleet-planning.css';

type Event = {key: string; kind: 'request' | 'hold' | 'confirmed' | 'out' | 'returned' | 'garage'; id: number; title: string; reference?: string; starts_local: string; ends_local: string | null; returned_on?: string | null; blocks_vehicle: boolean;overdue?:boolean;threatened?:boolean};
type Row = {vehicle: FleetPreview & {current_odometer_date: string | null}; in_garage: boolean; return_pending?:boolean;out_now?:boolean;overdue?:boolean; events: Event[]};
type Data = {data: Row[]; from: string; until: string; today: string; checked_at: string; counts: {vehicles: number; requests: number; garage: number}; total: number; page: number; last_page: number};
const DAY = 86400000;
const shift = (date: string, days: number) => new Date(Date.parse(date + 'T12:00Z') + days * DAY).toISOString().slice(0, 10);
const todayLocal = () => new Intl.DateTimeFormat('en-CA', {timeZone: 'Africa/Casablanca', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
const dateLabel = (date: string, year = false) => new Intl.DateTimeFormat('fr-FR', {day: 'numeric', month: 'short', ...(year ? {year: 'numeric'} : {}), timeZone: 'UTC'}).format(new Date(date.slice(0, 10) + 'T12:00Z'));
const timeLabel = (date: string) => dateLabel(date) + ' · ' + date.slice(11, 16);
function startOf(date: string, mode: string) {if (mode === 'month') return date.slice(0, 7) + '-01'; const day = new Date(date + 'T12:00Z').getUTCDay(); return shift(date, -(day === 0 ? 6 : day - 1));}
function span(event: Event, from: string, days: number) {
  const start = Math.max(0, (Date.parse(event.starts_local + 'Z') - Date.parse(from + 'T00:00Z')) / DAY);
  const end = event.kind==='out'&&event.overdue ? days : event.ends_local ? Math.min(days, (Date.parse(event.ends_local + 'Z') - Date.parse(from + 'T00:00Z')) / DAY) : days;
  return {left: `${start / days * 100}%`, width: `${Math.max(.12, end - start) / days * 100}%`} as CSSProperties;
}
function EventText({event}: {event: Event}) {
  return <><strong>{event.kind !== 'garage' ? event.title : event.returned_on ? 'Passage au garage' : 'Au garage · retour à enregistrer'}</strong><span>{event.kind !== 'garage' ? `${event.kind === 'returned'?'Retour enregistré · ':event.kind === 'out'?(event.overdue?'En location · retour en retard · ':'En location · '):event.threatened?'Réservation menacée · retour précédent en retard · ':event.kind === 'confirmed'?'Réservation confirmée · ':event.kind === 'hold'?'Réservation temporaire · ':''}${event.reference} · ${timeLabel(event.starts_local)} → ${timeLabel(event.ends_local!)}` : `${event.title} · depuis le ${dateLabel(event.starts_local)}${event.returned_on ? ' · retour le ' + dateLabel(event.returned_on) : ' · fin inconnue'}`}</span></>;
}

export default function FleetPlanning({revision, onOpen, onCreate}: {revision: number; onOpen: (id: number) => void; onCreate: (seed: RequestSeed) => void}) {
  const [mode, setMode] = useState<'week' | 'month'>('week'), [anchor, setAnchor] = useState(todayLocal);
  const [q, setQ] = useState(''), [search, setSearch] = useState(''), [filter, setFilter] = useState('all'), [page, setPage] = useState(1);
  const [data, setData] = useState<Data | null>(null), [busy, setBusy] = useState(true), [error, setError] = useState(''), [refresh, setRefresh] = useState(0);
  const [loadedKey, setLoadedKey] = useState('');
  const [garage, setGarage] = useState<{row: Row; event: Event} | null>(null);
  const from = startOf(anchor, mode);
  const days = mode === 'week' ? 7 : new Date(Date.UTC(Number(from.slice(0, 4)), Number(from.slice(5, 7)), 0)).getUTCDate();
  const dates = Array.from({length: days}, (_, i) => shift(from, i));
  const loadKey = `${from}|${days}|${search}|${filter}|${page}|${revision}|${refresh}`;
  useEffect(() => {const timeout = setTimeout(() => {setSearch(q.trim()); setPage(1);}, 250); return () => clearTimeout(timeout);}, [q]);
  useEffect(() => {
    const controller = new AbortController(); setBusy(true); setError('');
    void (async () => {
      try {
        const response = await fetch(`${api}/api/v1/reservations/planning?` + new URLSearchParams({from, days: String(days), q: search, filter, page: String(page)}), {credentials: 'include', cache: 'no-store', signal: AbortSignal.any([controller.signal, AbortSignal.timeout(20000)])});
        if (response.status === 401) {window.location.replace('/login'); return;}
        const body = await response.json();
        if (!response.ok) throw Error(body.message ?? 'Le planning n’a pas pu être chargé.');
        if (!controller.signal.aborted) {setData(body); setLoadedKey(loadKey);}
      } catch (e) {if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'Connexion impossible.');}
      finally {if (!controller.signal.aborted) setBusy(false);}
    })();
    return () => controller.abort();
  }, [from, days, search, filter, page, revision, refresh, loadKey]);
  useEffect(()=>{const timer=setInterval(()=>{if(document.visibilityState==='visible')setRefresh(n=>n+1);},30000);return()=>clearInterval(timer);},[]);
  const ready = !busy && !error && data !== null && q.trim() === search && loadedKey === loadKey;
  function move(direction: number) {setPage(1); setAnchor(mode === 'week' ? shift(from, direction * 7) : new Date(Date.UTC(Number(from.slice(0, 4)), Number(from.slice(5, 7)) - 1 + direction, 1, 12)).toISOString().slice(0, 10));}
  function chooseFilter(value: string) {setFilter(value); setPage(1);}
  function create(row: Row, date: string) {const start = date < (data?.today ?? todayLocal()) ? (data?.today ?? todayLocal()) : date; onCreate({vehicle: row.vehicle, start: start + 'T10:00', end: shift(start, 1) + 'T10:00'});}
  function open(row: Row, event: Event) {if (event.kind !== 'garage') onOpen(event.id); else setGarage({row, event});}
  function eventButton(row: Row, event: Event, timeline = false, index = 0) {return <button key={event.key} className={`fp-event ${event.kind} ${event.returned_on ? 'returned' : ''}`} style={timeline ? {...span(event, from, days), top: 12 + index * 59} : undefined} onClick={() => open(row, event)} aria-label={`Ouvrir ${event.reference ?? 'le dossier garage'} · ${row.vehicle.plate} · ${event.title}`} title={`${event.title} — ${event.kind !== 'garage' ? event.kind === 'returned'?'Retour physique enregistré':event.kind === 'confirmed'?'Réservation confirmée · voiture réservée':event.kind === 'hold'?'Réservation temporaire · voiture bloquée':'Demande, sans blocage' : event.returned_on ? 'Retour enregistré' : 'Retour non enregistré'}`}>
    {event.kind !== 'garage' ? <FileText size={15}/> : <Wrench size={15}/>}<div><EventText event={event}/></div><ArrowUpRight size={14}/>
  </button>;}

  return <section className="fp" aria-label="Planning du parc">
    <div className="fp-metrics" aria-label="Résumé du planning">
      <button aria-pressed={filter === 'all'} onClick={() => chooseFilter('all')}><span className="fp-metric-icon"><CarFront size={20}/></span><div><strong>{ready ? data.counts.vehicles : '—'}</strong><span>Voitures {search ? 'trouvées' : 'dans le parc'}</span></div><span className="fp-metric-end">Tout voir <ArrowUpRight size={15}/></span></button>
      <button aria-pressed={filter === 'requests'} onClick={() => chooseFilter('requests')}><span className="fp-metric-icon blue"><FileText size={20}/></span><div><strong>{ready ? data.counts.requests : '—'}</strong><span>Dossiers sur la période</span></div><span className="fp-metric-end">Demandes et réservations</span></button>
      <button aria-pressed={filter === 'garage'} onClick={() => chooseFilter('garage')}><span className="fp-metric-icon amber"><Wrench size={20}/></span><div><strong>{ready ? data.counts.garage : '—'}</strong><span>Voitures au garage maintenant</span></div><span className="fp-metric-end">Voir <ArrowUpRight size={15}/></span></button>
    </div>
    <div className="fp-board">
      <div className="fp-toolbar"><div><span className="fp-kicker">VOTRE PARC, DANS LE TEMPS</span><h2>{mode === 'month' ? new Intl.DateTimeFormat('fr-FR', {month: 'long', year: 'numeric', timeZone: 'UTC'}).format(new Date(from + 'T12:00Z')) : `${dateLabel(from)} — ${dateLabel(dates.at(-1)!, true)}`}</h2></div><div className="fp-navigation"><button aria-label="Période précédente" onClick={() => move(-1)}><ChevronLeft size={18}/></button><button onClick={() => {setAnchor(todayLocal()); setPage(1);}}>Aujourd’hui</button><button aria-label="Période suivante" onClick={() => move(1)}><ChevronRight size={18}/></button><div className="fp-mode"><button aria-pressed={mode === 'week'} onClick={() => {setMode('week'); setPage(1);}}>Semaine</button><button aria-pressed={mode === 'month'} onClick={() => {setMode('month'); setPage(1);}}>Mois</button></div></div></div>
      <div className="fp-tools"><div className="fp-search"><Search size={18}/><label className="sr-only" htmlFor="fp-search">Rechercher une voiture dans le planning</label><input id="fp-search" placeholder="Une voiture en tête ? Marque, modèle ou plaque…" maxLength={100} value={q} onChange={e => setQ(e.target.value)}/>{q && <button aria-label="Effacer la recherche du planning" onClick={() => setQ('')}><X size={16}/></button>}</div><label className="fp-date"><CalendarDays size={17}/><span>Aller au</span><input aria-label="Aller à une date" type="date" min="2000-01-01" max="2100-12-31" value={anchor} onChange={e => {if (e.target.validity.valid && /^\d{4}-\d{2}-\d{2}$/.test(e.target.value)) {setAnchor(e.target.value); setPage(1);}}}/></label><button className="fp-refresh" aria-label="Actualiser le planning" disabled={busy} onClick={() => setRefresh(n => n + 1)}><RefreshCw size={17}/></button></div>
      <div className="fp-legend"><span><i className="request"/>Demande · ne bloque pas</span><span><i className="hold"/>Réservation temporaire · bloque</span><span><i className="confirmed"/>Réservation confirmée · bloque</span><span><i className="out"/>En location · voiture sortie</span><span><i className="garage"/>Au garage · retour non enregistré</span><span><i className="returned"/>Passage au garage terminé</span>{filter !== 'all' && <button onClick={() => chooseFilter('all')}>Retirer le filtre <X size={13}/></button>}</div>
      {error ? <div className="fp-empty" role="alert"><Info size={28}/><h3>Planning indisponible</h3><p>{error}</p><button onClick={() => setRefresh(n => n + 1)}>Réessayer</button></div> : !ready ? <div className="fp-empty" role="status"><CalendarDays size={30}/><p>Chargement du planning…</p></div> : !data.data.length ? <div className="fp-empty"><Search size={30}/><h3>Aucune voiture pour cette sélection</h3><p>Changez la recherche, le filtre ou la période.</p><button onClick={() => {setQ(''); chooseFilter('all');}}>Afficher le parc</button></div> : <>
        <div className="fp-grid-scroll" tabIndex={0} role="region" aria-label="Calendrier des véhicules, défilement horizontal en vue mois">
          <div className={`fp-grid ${mode}`} style={{'--fp-days': days} as CSSProperties}>
            <div className="fp-grid-head"><div className="fp-car-head">Véhicule <span>Situation actuelle</span></div><div className="fp-dates">{dates.map(date => <div key={date} className={`${date === data.today ? 'today' : ''} ${[0, 6].includes(new Date(date + 'T12:00Z').getUTCDay()) ? 'weekend' : ''}`}><span>{new Intl.DateTimeFormat('fr-FR', {weekday: 'short', timeZone: 'UTC'}).format(new Date(date + 'T12:00Z'))}</span><strong>{Number(date.slice(8))}</strong>{date === data.today && <i/>}</div>)}</div></div>
            {data.data.map(row => <div key={row.vehicle.id} className="fp-row" style={{minHeight: Math.max(118, row.events.length * 59 + 24)}}>
              <div className="fp-car"><div className="fp-car-title"><CarFront size={19}/><button onClick={() => setQ(row.vehicle.plate)} aria-label={`Isoler ${row.vehicle.plate}`}><strong>{row.vehicle.make} {row.vehicle.model}</strong><span>{row.vehicle.plate}</span></button></div><span className={`fp-current ${row.in_garage ? 'garage' : ''}`}>{row.in_garage ? <Wrench size={12}/> : <Check size={12}/>} {row.in_garage ? 'Au garage maintenant' : row.out_now?(row.overdue?'En location · retour en retard':'En location actuellement'):row.return_pending?'Retour enregistré · disponibilité à valider':'Aucun arrêt signalé'}</span><a href={`/vehicles?vehicle=${row.vehicle.id}`}>Fiche véhicule <ArrowUpRight size={12}/></a></div>
              <div className="fp-track"><div className="fp-day-cells">{dates.map(date => <button key={date} className={`${date === data.today ? 'today' : ''} ${[0, 6].includes(new Date(date + 'T12:00Z').getUTCDay()) ? 'weekend' : ''}`} disabled={row.in_garage || row.overdue || date < data.today} aria-label={`Préparer une demande pour ${row.vehicle.plate} le ${date}`} title="Choisir ce jour, puis vérifier les dates" onClick={() => create(row, date)}><Plus size={15}/></button>)}</div>{row.events.length ? row.events.map((event, i) => eventButton(row, event, true, i)) : <span className="fp-no-event">Aucun événement sur cette période<span>Choisissez un jour pour vérifier les dates</span></span>}</div>
            </div>)}
          </div>
        </div>
        {data.total === 1 && <div className="fp-selected-agenda"><header><div><h3>Le détail de cette voiture</h3><p>{data.data[0].vehicle.plate} · {dateLabel(from)} au {dateLabel(dates.at(-1)!)}</p></div>{!data.data[0].in_garage && !data.data[0].overdue && <button className="fp-verify" onClick={() => create(data.data[0], from)}><CalendarDays size={16}/>Vérifier les dates <ChevronRight size={16}/></button>}</header>{data.data[0].events.length ? <div className="fp-agenda">{data.data[0].events.map(event => eventButton(data.data[0], event))}</div> : <p className="fp-agenda-empty">Aucun événement enregistré sur cette période.</p>}</div>}
        <div className="fp-mobile">{data.data.map(row => <article className="fp-mobile-car" key={row.vehicle.id}><header><CarFront size={23}/><div><h3>{row.vehicle.make} {row.vehicle.model}</h3><span>{row.vehicle.plate}</span></div><a href={`/vehicles?vehicle=${row.vehicle.id}`} aria-label={`Fiche véhicule ${row.vehicle.plate}`}><ArrowUpRight size={18}/></a></header><span className={`fp-current ${row.in_garage ? 'garage' : ''}`}>{row.in_garage ? <Wrench size={13}/> : <Check size={13}/>} {row.in_garage ? 'Au garage maintenant' : row.out_now?(row.overdue?'En location · retour en retard':'En location actuellement'):row.return_pending?'Retour enregistré · disponibilité à valider':'Aucun arrêt signalé'}</span><div className="fp-agenda">{row.events.length ? row.events.map(event => eventButton(row, event)) : <p>Aucun événement sur cette période.</p>}</div>{!row.in_garage && !row.overdue && <button className="fp-verify" onClick={() => create(row, from)}><CalendarDays size={16}/>Vérifier les dates <ChevronRight size={16}/></button>}</article>)}</div>
        <footer className="fp-footer"><span>{data.total} voiture(s) · Page {data.page} / {data.last_page}</span><div><button aria-label="Voitures précédentes" disabled={data.page <= 1} onClick={() => setPage(data.page - 1)}><ChevronLeft size={17}/></button><button aria-label="Voitures suivantes" disabled={data.page >= data.last_page} onClick={() => setPage(data.page + 1)}><ChevronRight size={17}/></button></div></footer>
      </>}
    </div>
    <div className="fp-scope"><Info size={18}/><p><strong>Une demande n’est pas une réservation.</strong> Ce planning affiche les demandes, les réservations temporaires ou confirmées et les passages au garage enregistrés. Les réservations confirmées bloquent la voiture. Une location en cours reste sortie tant que son retour réel n’est pas enregistré. Un retard bloque les nouvelles réservations et signale les suivantes à vérifier. Une case vide signifie « aucun événement enregistré ».</p></div>
    {ready && <p className="fp-updated"><Clock3 size={13}/>Heure du Maroc · Actualisé à {new Intl.DateTimeFormat('fr-FR', {timeZone: 'Africa/Casablanca', hour: '2-digit', minute: '2-digit'}).format(new Date(data.checked_at))}</p>}
    {garage && <MaintenanceFile vehicleId={garage.row.vehicle.id} vehicleLabel={`${garage.row.vehicle.plate} · ${garage.row.vehicle.make} ${garage.row.vehicle.model}`} entryId={garage.event.id} today={data?.today ?? todayLocal()} odometer={{value: garage.row.vehicle.current_odometer, date: garage.row.vehicle.current_odometer_date}} onClose={() => {setGarage(null); setRefresh(n => n + 1);}} onSaved={() => setRefresh(n => n + 1)}/>}
  </section>;
}
