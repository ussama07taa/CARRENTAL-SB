'use client';

import {useCallback, useEffect, useRef, useState} from 'react';
import {CalendarDays, CarFront, Check, ChevronLeft, ChevronRight, Info, Plus, RefreshCw, Search, ShieldCheck, UserRound, X} from 'lucide-react';
import {api, apiMutation} from '../_lib/auth-client';
import {focusFieldError, type FieldErrors} from '../_lib/form-validation';
import NewRequestClient, {type NewClientState} from './new-request-client';
import FieldHelp from '../_components/field-help';
import {VehiclePhoto, type FleetPreview} from '../_components/fleet-spotlight';
import {localDate, duration, type Client, type Car, type Period} from './request-types';

type Mode = 'dates' | 'vehicle';
type Stage = 'start' | 'dates' | 'cars' | 'client';
type Page<T> = {data: T[]; page: number; last_page: number; total: number};
type Available = Page<Car> & {available_count: number; unavailable_count: number; period: Period; checked_at: string};
type Proof = {readiness_warning?:string|null;vehicleId: number; available: boolean; reason: string | null; period: Period; checkedAt: string};

function todayInMorocco() {
  const parts = new Intl.DateTimeFormat('en', {timeZone:'Africa/Casablanca', year:'numeric', month:'2-digit', day:'2-digit'}).formatToParts(new Date());
  const value = (type: string) => parts.find(part => part.type === type)!.value;
  return `${value('year')}-${value('month')}-${value('day')}`;
}
const pastDepartureMessage = 'La date de départ est passée. Choisissez une nouvelle date, aujourd’hui ou plus tard (heure du Maroc).';

export type RequestSeed = {vehicle: FleetPreview; start: string; end: string};
export default function RequestForm({onClose, onSaved, initial}: {onClose: () => void; onSaved: (id: number) => void; initial?: RequestSeed}) {
  const dialog = useRef<HTMLDialogElement>(null), form = useRef<HTMLFormElement>(null);
  const lock = useRef(false), key = useRef(crypto.randomUUID()), checkController = useRef<AbortController | null>(null);
  const [mode, setMode] = useState<Mode>(initial ? 'vehicle' : 'dates'), [stage, setStage] = useState<Stage>(initial ? 'dates' : 'start');
  const [client, setClient] = useState<Client | null>(null), [car, setCar] = useState<FleetPreview | null>(initial?.vehicle ?? null);
  const [start, setStart] = useState(initial?.start ?? ''), [end, setEnd] = useState(initial?.end ?? ''), [proof, setProof] = useState<Proof | null>(null);
  const [query, setQuery] = useState(''), [search, setSearch] = useState(''), [page, setPage] = useState(1), [show, setShow] = useState('available');
  const [clients, setClients] = useState<Page<Client> | null>(null), [fleet, setFleet] = useState<Page<FleetPreview> | null>(null), [available, setAvailable] = useState<Available | null>(null);
  const [loading, setLoading] = useState(false), [checking, setChecking] = useState(false), [saving, setSaving] = useState(false), [uncertain, setUncertain] = useState(false);
  const [error, setError] = useState(''), [fields, setFields] = useState<FieldErrors>({}), [retry, setRetry] = useState(0);
  const [newClientOpen,setNewClientOpen]=useState(false),[newClientState,setNewClientState]=useState<NewClientState>({dirty:false,busy:false,uncertain:false}),[createdClientId,setCreatedClientId]=useState<number|null>(null);
  const handleNewClientState=useCallback((state:NewClientState)=>setNewClientState(state),[]);
  const frozen = saving || uncertain || newClientState.busy || newClientState.uncertain;
  useEffect(()=>{const warn=(event:BeforeUnloadEvent)=>{if(newClientOpen&&(newClientState.dirty||newClientState.busy||newClientState.uncertain)){event.preventDefault();event.returnValue='';}};window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[newClientOpen,newClientState]);
  function leaveNewClient(){if(newClientState.busy||newClientState.uncertain)return false;if(newClientOpen&&newClientState.dirty&&!window.confirm('Abandonner les informations du nouveau client non enregistrées ? La voiture et les dates restent conservées.'))return false;setNewClientOpen(false);return true;}
  function close(){if(!saving&&!uncertain&&leaveNewClient())onClose();}
  function selectNewClient(person:Client,created:boolean){setClient(person);setCreatedClientId(created?person.id:null);setNewClientOpen(false);setNewClientState({dirty:false,busy:false,uncertain:false});setQuery('');setSearch('');setPage(1);clear('customer_id');setError('');setRetry(value=>value+1);}

  const catalog = stage === 'start' && mode === 'vehicle';
  const dateScreen = (stage === 'start' && mode === 'dates') || stage === 'dates';
  const step = stage === 'client' ? 3 : stage === 'start' ? 1 : 2;
  const verified = !!(car && proof?.available && proof.vehicleId === car.id && proof.period.starts_local === start && proof.period.ends_local === end);

  useEffect(() => {
    const origin = document.activeElement as HTMLElement;
    dialog.current?.showModal();
    return () => {checkController.current?.abort(); origin?.focus();};
  }, []);
  useEffect(() => {const timer = setTimeout(() => {setSearch(query.trim()); setPage(1);}, 250); return () => clearTimeout(timer);}, [query]);
  useEffect(() => {
    if (!catalog && stage !== 'cars' && stage !== 'client') {setLoading(false); return;}
    const controller = new AbortController();
    setLoading(true); setError('');
    if (stage === 'cars') setAvailable(null);
    void (async () => {
      try {
        const endpoint = stage === 'client' ? 'customers?' + new URLSearchParams({q: search, page: String(page), rental_status: '1'}) : catalog ? 'vehicles?' + new URLSearchParams({q: search, page: String(page)}) : 'reservations/availability?' + new URLSearchParams({starts_local: start, ends_local: end, q: search, page: String(page), show});
        const response = await fetch(`${api}/api/v1/${endpoint}`, {credentials: 'include', cache: 'no-store', signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)])});
        if (response.status === 401) {window.location.replace('/login'); return;}
        const body = await response.json();
        if (controller.signal.aborted) return;
        if (!response.ok) {
          if (body.errors) {
            setFields(body.errors);
            if (body.errors.starts_local || body.errors.ends_local) {setStage(mode === 'vehicle' ? 'dates' : 'start'); setProof(null);}
          }
          throw Error(body.message ?? 'Le chargement a échoué. Réessayez.');
        }
        if (stage === 'client') setClients(body); else if (catalog) setFleet(body); else {
          setAvailable(body);
          setProof(current => current && (body.data as Car[]).some(vehicle => vehicle.id === current.vehicleId && !vehicle.available) ? null : current);
        }
      } catch (e) {if (!controller.signal.aborted) {setError(e instanceof Error ? e.message : 'Connexion impossible.'); if (stage === 'cars') setProof(null);}}
      finally {if (!controller.signal.aborted) setLoading(false);}
    })();
    return () => controller.abort();
  }, [catalog, stage, mode, search, page, show, start, end, retry]);
  useEffect(() => {if (Object.keys(fields).length) focusFieldError(form.current, fields);}, [stage, fields]);
  useEffect(() => {
    if (stage === 'dates' && proof && !checking) dialog.current?.querySelector('.rq-availability-answer')?.scrollIntoView({block: 'nearest'});
  }, [stage, proof, checking]);

  function clear(field: string) {setFields(current => {const next = {...current}; delete next[field]; return next;});}
  function cancelCheck() {checkController.current?.abort(); setChecking(false);}
  function navigate(next: Stage) {
    if(!leaveNewClient())return;
    cancelCheck(); setError(''); setStage(next); setQuery(''); setSearch(''); setPage(1);
    dialog.current?.querySelector('.rq-dialog-body')?.scrollTo(0, 0);
  }
  function chooseMode(value: Mode) {if (value === mode) return; setMode(value); setShow('available'); navigate('start');}
  function editDate(name: string, value: string) {
    cancelCheck(); if (name === 'starts_local') setStart(value); else setEnd(value);
    setProof(null); setAvailable(null); clear(name); setError('');
  }
  function datesValid() {
    const errors: FieldErrors = {};
    if (!start) errors.starts_local = ['Renseignez la date et l’heure de départ.'];
    else if (start.slice(0, 10) < todayInMorocco()) errors.starts_local = [pastDepartureMessage];
    if (!end) errors.ends_local = ['Renseignez la date et l’heure de retour.'];
    else if (start && end <= start) errors.ends_local = ['Le retour doit être après le départ.'];
    setFields(errors);
    return Object.keys(errors).length === 0;
  }
  async function checkCar() {
    if (!car || !datesValid() || checking) return;
    const controller = new AbortController(); checkController.current = controller;
    setChecking(true); setProof(null); setError('');
    try {
      for (const filter of ['available', 'unavailable']) {
        let current = 1, last = 1;
        do {
          const params = new URLSearchParams({starts_local: start, ends_local: end, q: car.plate, page: String(current), show: filter});
          const response = await fetch(`${api}/api/v1/reservations/availability?${params}`, {credentials: 'include', cache: 'no-store', signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)])});
          if (response.status === 401) {window.location.replace('/login'); return;}
          const body = await response.json();
          if (controller.signal.aborted) return;
          if (!response.ok) {if (body.errors) setFields(body.errors); throw Error(body.message ?? 'La vérification a échoué. Réessayez.');}
          const vehicle = (body.data as Car[]).find(item => item.id === car.id);
          if (vehicle) {setProof({readiness_warning: vehicle.readiness_warning, vehicleId: vehicle.id, available: vehicle.available, reason: vehicle.reason, period: body.period, checkedAt: body.checked_at}); return;}
          last = body.last_page; current++;
        } while (current <= last);
      }
      throw Error('La voiture est introuvable. Revenez au choix du véhicule.');
    } catch (e) {if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'Connexion impossible.');}
    finally {if (!controller.signal.aborted) setChecking(false);}
  }
  function selectCar(vehicle: FleetPreview & {readiness_warning?:string|null}, result?: Available) {
    setCar(vehicle); clear('vehicle_id'); setProof(result ? {readiness_warning: vehicle.readiness_warning, vehicleId: vehicle.id, available: true, reason: null, period: result.period, checkedAt: result.checked_at} : null);
    if (catalog) navigate('dates');
  }
  function alternatives() {setMode('dates'); setShow('available'); setCar(null); setProof(null); navigate('cars');}
  function back() {
    if (stage === 'client') navigate(mode === 'vehicle' ? 'dates' : 'cars');
    else if (stage !== 'start') navigate('start');
    else close();
  }
  async function save() {
    if (lock.current || !verified || !car) return;
    // Preserve an uncertain request's idempotent retry, even after midnight.
    if (!uncertain && !datesValid()) {setProof(null); navigate(mode === 'vehicle' ? 'dates' : 'start'); return;}
    if (!client) {setFields({customer_id: ['Choisissez un client dans la liste.']}); return;}
    lock.current = true; setSaving(true); setError(''); setFields({});
    try {
      const response = await apiMutation('reservations', 'POST', {customer_id: client.id, vehicle_id: car.id, starts_local: start, ends_local: end, request_key: key.current});
      if (response.status === 401) {window.location.replace('/login'); return;}
      const body = await response.json();
      if (response.ok) {onSaved(body.data.id); return;}
      setUncertain(false);
      if (body.code === 'REQUEST_ALREADY_USED' && body.id) {onSaved(body.id); return;}
      if (body.errors) {
        if (body.errors.starts_local || body.errors.ends_local) {setProof(null); navigate(mode === 'vehicle' ? 'dates' : 'start');}
        else if (body.errors.vehicle_id) {setProof(null); navigate(mode === 'vehicle' ? 'dates' : 'cars'); setRetry(n => n + 1);}
        setFields(body.errors);
      }
      setError(body.message ?? 'L’enregistrement a échoué. Réessayez.');
    } catch {setUncertain(true); setError('La réponse du serveur n’est pas arrivée. Réessayez le même enregistrement : son identifiant empêche un doublon.');}
    finally {lock.current = false; setSaving(false);}
  }
  function submit() {
    if(newClientOpen)return;
    if (stage === 'client') {void save(); return;}
    if (dateScreen) {
      if (!datesValid()) return;
      if (mode === 'vehicle') {if (verified) navigate('client'); else void checkCar();}
      else {setShow('available'); navigate('cars');}
    } else if (stage === 'cars' && verified) navigate('client');
  }
  const fieldError = (name: string) => fields[name]?.length ? <p role="alert" className="rq-field-error" id={`rq-error-${name}`}>{fields[name][0]}</p> : null;
  const list = stage === 'client' ? clients : catalog ? fleet : available;
  const resultsCurrent = available?.period.starts_local === start && available?.period.ends_local === end;
  const pager = <div className="rq-pager"><button type="button" className="secondary" disabled={loading || !list || list.page <= 1} onClick={() => setPage(n => n - 1)}>Précédent</button><span>{list?.page ?? 1} / {list?.last_page ?? 1}</span><button type="button" className="secondary" disabled={loading || !list || list.page >= list.last_page} onClick={() => setPage(n => n + 1)}>Suivant</button></div>;
  const searchBox = (isClient: boolean) => <><label htmlFor={isClient ? 'rq-client-search' : 'rq-vehicle-search'}>{isClient ? 'Nom ou téléphone' : 'Plaque, marque ou modèle'} <FieldHelp field={isClient ? 'request_client' : 'request_vehicle'} label={isClient ? 'Choisir le client' : 'Choisir une voiture'}/></label><div className="rq-search"><Search size={18}/><input id={isClient ? 'rq-client-search' : 'rq-vehicle-search'} name={isClient ? 'customer_id' : 'vehicle_id'} value={query} maxLength={100} placeholder={isClient ? 'Rechercher un client…' : 'Ex. Clio ou 12345-A-6'} aria-invalid={!!fields[isClient ? 'customer_id' : 'vehicle_id']} aria-describedby={fields[isClient ? 'customer_id' : 'vehicle_id'] ? `rq-error-${isClient ? 'customer_id' : 'vehicle_id'}` : undefined} onChange={e => {setQuery(e.target.value); clear(isClient ? 'customer_id' : 'vehicle_id');}}/></div>{fieldError(isClient ? 'customer_id' : 'vehicle_id')}</>;
  const actionLabel = saving ? 'Enregistrement…' : stage === 'client' ? uncertain ? 'Réessayer l’enregistrement' : 'Enregistrer la demande' : dateScreen ? mode === 'dates' ? 'Voir les voitures disponibles' : checking ? 'Vérification…' : verified ? 'Choisir le client' : 'Vérifier la disponibilité' : 'Choisir le client';

  return <dialog ref={dialog} className="rq-dialog rq-create" aria-labelledby="rq-create-title" onCancel={e => {e.preventDefault(); if (!lock.current) close();}}>
    <form ref={form} noValidate onSubmit={e => {e.preventDefault(); submit();}}>
      <header><div><span className="rq-eyebrow">NOUVEAU DOSSIER</span><h2 id="rq-create-title">Créer une demande</h2><p>Trouvez la voiture pour les bonnes dates, puis choisissez le client.</p></div><button type="button" className="rq-close" disabled={frozen} onClick={close} aria-label="Fermer la création"><X size={21}/></button></header>
      <nav className="rq-steps" aria-label="Étapes de la demande">{(mode === 'vehicle' ? ['Voiture', 'Disponibilité', 'Client'] : ['Dates', 'Voiture', 'Client']).map((label, i) => <button type="button" key={label} aria-current={step === i + 1 ? 'step' : undefined} disabled={i + 1 >= step || frozen} onClick={() => navigate(i === 0 ? 'start' : mode === 'vehicle' ? 'dates' : 'cars')}><span>{i + 1 < step ? <Check size={14}/> : i + 1}</span>{label}</button>)}</nav>
      <div className="rq-dialog-body">
        {error && <div className="rq-error" role="alert">{error}{!frozen && !dateScreen && <button type="button" className="secondary" onClick={() => setRetry(n => n + 1)}><RefreshCw size={14}/>Réessayer</button>}</div>}
        <div className="rq-create-layout"><fieldset disabled={saving||uncertain} className="rq-form-content">
          {stage === 'start' && <div className="rq-start-modes" aria-label="Commencer la demande"><button type="button" aria-pressed={mode === 'dates'} onClick={() => chooseMode('dates')}><CalendarDays size={20}/><span><strong>J’ai les dates</strong><small>Voir les voitures disponibles</small></span>{mode === 'dates' && <Check size={16}/>}</button><button type="button" aria-pressed={mode === 'vehicle'} onClick={() => chooseMode('vehicle')}><CarFront size={20}/><span><strong>J’ai une voiture en tête</strong><small>Vérifier cette voiture</small></span>{mode === 'vehicle' && <Check size={16}/>}</button></div>}
          {catalog && <section><div className="rq-section-heading"><CarFront size={22}/><div><h3>Quelle voiture intéresse le client ?</h3><p>Choisissez-la, puis vérifiez ses dates. Aucun client à saisir pour consulter.</p></div></div>{searchBox(false)}{loading ? <p className="rq-empty" role="status">Chargement du parc…</p> : !error && <><div className="rq-car-grid">{fleet?.data.map(vehicle => <article className="rq-car" key={vehicle.id}><VehiclePhoto vehicle={vehicle}/><div className="rq-car-description"><h4>{vehicle.make} {vehicle.model}</h4><p className="rq-car-plate">{vehicle.plate}</p><p className="rq-catalog-note">Disponibilité à vérifier selon les dates</p></div><button type="button" className="secondary" aria-label={`Vérifier ${vehicle.plate}`} onClick={() => selectCar(vehicle)}>Vérifier les dates <ChevronRight size={14}/></button></article>)}</div>{!fleet?.data.length && <p className="rq-empty">Aucune voiture trouvée. Essayez une autre plaque ou un autre modèle.</p>}{pager}</>}</section>}
          {dateScreen && <section><div className="rq-section-heading"><CalendarDays size={22}/><div><h3>{mode === 'vehicle' ? 'Est-elle disponible pour ces dates ?' : 'Pour quelle période ?'}</h3><p>Dates et heures du Maroc. Vérifiez le départ et le retour.</p></div></div>
            {mode === 'vehicle' && car && <div className="rq-car rq-chosen-car"><VehiclePhoto vehicle={car}/><div className="rq-car-description"><h4>{car.make} {car.model}</h4><p className="rq-car-plate">{car.plate}</p></div><button type="button" className="rq-link" onClick={() => navigate('start')}>Changer de voiture</button></div>}
            <div className="rq-dates">{[{name: 'starts_local', label: 'Départ souhaité', value: start}, {name: 'ends_local', label: 'Retour souhaité', value: end}].map(field => <div key={field.name}><label htmlFor={`rq-${field.name}`}>{field.label} * <FieldHelp field={`request_${field.name}`} label={field.label}/></label><input type="datetime-local" id={`rq-${field.name}`} name={field.name} value={field.value} min={field.name === 'starts_local' ? todayInMorocco()+'T00:00' : start || undefined} required aria-invalid={!!fields[field.name]} aria-describedby={fields[field.name] ? `rq-error-${field.name}` : undefined} onChange={e => editDate(field.name, e.target.value)}/>{fieldError(field.name)}</div>)}</div>
            {fieldError('vehicle_id')}
            {mode === 'vehicle' && <div aria-live="polite">{checking ? <p role="status">Vérification de cette voiture…</p> : proof && proof.vehicleId === car?.id && proof.period.starts_local === start && proof.period.ends_local === end ? <div className={`rq-availability-answer ${proof.available ? 'available' : 'blocked'}`}><strong>{proof.available ? 'Créneau disponible pour cette demande' : 'Indisponible pour ces dates'}</strong><p>{proof.available ? `${duration(proof.period.duration_minutes)} · Créneau libre au planning. La présence et la disponibilité après retour seront contrôlées avant la remise des clés.` : proof.reason}</p>{proof.readiness_warning&&<p className="dp-warning">{proof.readiness_warning}</p>}{!proof.available && <button type="button" className="secondary" onClick={alternatives}>Voir les alternatives <ChevronRight size={15}/></button>}</div> : <p className="rq-check-prompt"><Info size={16}/>Renseignez les dates, puis cliquez sur « Vérifier la disponibilité ».</p>}</div>}
            <p className="rq-muted">Contrôle des immobilisations connues, revérifié à l’enregistrement. Une demande ne bloque pas la voiture. Le blocage et la confirmation se font ensuite dans son dossier.</p>
          </section>}
          {stage === 'cars' && <section><div className="rq-section-heading"><CarFront size={22}/><div><h3>Les voitures pour votre période</h3><p>{localDate(start)} → {localDate(end)}{available && resultsCurrent ? ` · ${duration(available.period.duration_minutes)}` : ''}</p></div></div>{searchBox(false)}<div className="rq-car-tabs">{[['available', 'Disponibles'], ['unavailable', 'Indisponibles']].map(([value, label]) => <button type="button" key={value} aria-pressed={show === value} onClick={() => {setShow(value); setPage(1);}}>{label} {loading || !available ? '…' : value === 'available' ? available.available_count : available.unavailable_count}</button>)}</div>
            {loading ? <p className="rq-empty" role="status">Vérification des voitures…</p> : available && resultsCurrent && !error && <><div className="rq-car-grid">{available.data.map(vehicle => <article className={`rq-car ${car?.id === vehicle.id && verified ? 'selected' : ''}`} key={vehicle.id}><VehiclePhoto vehicle={vehicle}/><div className="rq-car-description"><h4>{vehicle.make} {vehicle.model}</h4><p className="rq-car-plate">{vehicle.plate}</p><p className={vehicle.available ? 'rq-available' : 'rq-unavailable'}>{vehicle.available ? 'Créneau disponible · remise à contrôler' : vehicle.reason}</p>{vehicle.readiness_warning&&<p className="dp-warning">{vehicle.readiness_warning}</p>}</div><button type="button" className={car?.id === vehicle.id && verified ? 'primary' : 'secondary'} disabled={!vehicle.available} aria-pressed={car?.id === vehicle.id && verified} aria-label={`Choisir ${vehicle.plate}`} onClick={() => selectCar(vehicle, available)}>{car?.id === vehicle.id && verified ? <><Check size={16}/>Sélectionnée</> : 'Choisir'}</button></article>)}</div>{!available.data.length && <p className="rq-empty">{show === 'available' ? 'Aucune voiture disponible pour cette recherche. Modifiez la recherche ou les dates.' : 'Aucune voiture indisponible dans cette recherche.'}</p>}{pager}</>}
            <p className="rq-muted">Les voitures au garage sans retour enregistré ne peuvent pas être choisies. Une demande enregistrée ne réserve pas la voiture.</p>
          </section>}
          {stage === 'client' && <section><div className="rq-section-heading"><UserRound size={22}/><div><h3>Pour quel client ?</h3><p>La voiture et les dates sont choisies. Reliez maintenant le client à la demande.</p></div></div>{!newClientOpen&&<div className="rq-client-toolbar"><p>Le client n’existe pas encore ? Ajoutez-le ici sans quitter la demande.</p><button type="button" className="secondary" disabled={frozen} onClick={()=>{setNewClientOpen(true);setError('');}}><Plus size={17}/>Nouveau client</button></div>}{newClientOpen?<NewRequestClient onSelect={selectNewClient} onCancel={()=>void leaveNewClient()} onStateChange={handleNewClientState}/>:<>{searchBox(true)}{client && <div className="rq-selection"><Check size={17}/><span><strong>{client.first_name} {client.last_name}</strong> · {client.phone}</span></div>}{createdClientId===client?.id&&<p className="rq-new-client-saved" role="status">Fichier client créé et sélectionné. Vous pouvez enregistrer la demande.</p>}{loading ? <p className="rq-empty" role="status">Chargement des clients…</p> : !error && <><div className="rq-client-options">{clients?.data.map(person => <button type="button" key={person.id} className={`rq-client-option ${client?.id === person.id ? 'selected' : ''}`} aria-pressed={client?.id === person.id} onClick={() => {setClient(person); clear('customer_id');}}><span className="rq-client-initial">{person.first_name[0]}{person.last_name[0]}</span><span><strong>{person.first_name} {person.last_name}</strong>{person.in_rental&&<span className="rq-client-renting">En location</span>}<small>{person.phone}</small></span>{client?.id === person.id ? <Check size={19}/> : <ChevronRight size={18}/>}</button>)}</div>{!clients?.data.length && <p className="rq-empty">Aucun client trouvé. Utilisez « Nouveau client » pour créer son fichier ici. La voiture et les dates restent conservées.</p>}{pager}<button type="button" className="rq-link" onClick={() => setRetry(n => n + 1)}>Actualiser les clients</button></>}</>}</section>}
        </fieldset>
        <aside className="rq-review" aria-label="Résumé de la demande"><span className="rq-eyebrow">VOTRE DEMANDE</span><h3>Le récapitulatif</h3><p className="rq-review-intro">Vos choix restent visibles à chaque étape.</p>
          <div className="rq-review-item"><CarFront size={17}/><div><span>Voiture</span><strong>{car ? `${car.make} ${car.model}` : 'À choisir'}</strong>{car && <small>{car.plate}</small>}{car && <small>{verified ? 'Créneau vérifié · remise à contrôler' : proof?.vehicleId === car.id && !proof.available && proof.period.starts_local === start && proof.period.ends_local === end ? 'Indisponible pour ces dates' : 'Disponibilité à vérifier'}</small>}</div>{car && stage !== 'start' && <button type="button" className="rq-link" disabled={frozen} onClick={() => navigate(mode === 'vehicle' ? 'start' : 'cars')}>Modifier<span className="sr-only"> la voiture</span></button>}</div>
          <div className="rq-review-item"><CalendarDays size={17}/><div><span>Départ souhaité</span><strong>{start ? localDate(start) : 'À renseigner'}</strong><span>Retour souhaité</span><strong>{end ? localDate(end) : 'À renseigner'}</strong>{verified && proof && <small>{duration(proof.period.duration_minutes)}</small>}</div>{!dateScreen && start && <button type="button" className="rq-link" disabled={frozen} onClick={() => navigate(mode === 'vehicle' ? 'dates' : 'start')}>Modifier<span className="sr-only"> les dates</span></button>}</div>
          <div className="rq-review-item"><UserRound size={17}/><div><span>Client</span><strong>{client ? `${client.first_name} ${client.last_name}` : 'À choisir après la voiture'}</strong>{client && <small>{client.phone}</small>}</div></div>
          <div className="rq-review-note"><ShieldCheck size={18}/><p><strong>Demande uniquement</strong>Enregistrer conserve le dossier, sans réserver la voiture ni enregistrer de paiement.</p></div>
        </aside></div>
      </div><footer><button type="button" className="secondary" disabled={frozen} onClick={back}>{stage === 'start' ? 'Annuler' : <><ChevronLeft size={16}/>Retour</>}</button><span className="rq-footer-context">Étape {step} / 3{catalog ? ' · Choisissez une voiture dans la liste' : ''}</span>{!catalog && <button type="submit" className="primary" disabled={saving || checking || (stage === 'cars' && (!verified || loading)) || (stage === 'client' && (!verified||newClientOpen)) || newClientState.busy || newClientState.uncertain}>{actionLabel}{stage !== 'client' && <ChevronRight size={17}/>}</button>}</footer>
    </form>
  </dialog>;
}
