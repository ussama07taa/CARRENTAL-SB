'use client';
import {rentalMoney} from './rental-payment';

import {useEffect, useState} from 'react';
import {ArrowDownLeft, ArrowUpRight, CalendarDays, CarFront, Eye, Phone, Flag, Check, KeyRound, ChevronLeft, ChevronRight, Clock3, FileText, Plus, RefreshCw, Search, X} from 'lucide-react';
import {api} from '../_lib/auth-client';
import {refreshActivity} from '../_components/activity-feed';
import FieldHelp from '../_components/field-help';

import RequestFile from './request-file';
import RequestActions from './request-actions';
import RequestForm, {type RequestSeed} from './request-form';
import FleetPlanning from './fleet-planning';
import {duration, type RentalRequest, type Client} from './request-types';
import './request-list.css';
import './reservation-home.css';
import './reservation-directory.css';

type Listing = {can_manage_requests?:boolean;trash?:boolean;data: DirectoryRow[]; total: number; page: number; last_page: number; counts: Partial<Record<RentalRequest['status'], number>>; matching_total: number; vehicles: RentalRequest['vehicle'][]};
const states = [['demande', 'Demandes'], ['temporaire', 'Temporaires'], ['confirmee', 'Confirmées'], ['en_location', 'En location'], ['retournee', 'Retournées'], ['annulee', 'Annulées']] as const;
const requestedDay = (value: string) => new Intl.DateTimeFormat('fr-FR', {day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC'}).format(new Date(value + 'Z'));

type DirectoryRow = RentalRequest & {total_centimes?: number | null; vehicle: RentalRequest['vehicle'] & {cover_id?: number | null}};
const statusLabels = {demande: 'Demande', temporaire: 'Temporaire', confirmee: 'Confirmée', en_location: 'En location', retournee: 'Retournée', annulee: 'Annulée'};
function VehicleThumbnail({row}: {row: DirectoryRow}) {
  const [failed, setFailed] = useState(false);
  return row.vehicle.cover_id && !failed ? <img className="rq-car-photo" src={`${api}/api/v1/vehicles/${row.vehicle.id}/photos/${row.vehicle.cover_id}/content`} alt="" width={62} height={42} loading="lazy" onError={() => setFailed(true)}/> : <span className="rq-car-placeholder"><CarFront size={27}/></span>;
}

export default function Requests() {
  const [data, setData] = useState<Listing | null>(null), [q, setQ] = useState(''), [search, setSearch] = useState('');
  const [page, setPage] = useState(1), [busy, setBusy] = useState(true), [error, setError] = useState(''), [revision, setRevision] = useState(0);
  const [trash,setTrash]=useState(false),[notice,setNotice]=useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [status, setStatus] = useState(''), [vehicle, setVehicle] = useState(''), [source, setSource] = useState(''), [sort, setSort] = useState('newest');
  useEffect(()=>{const params=new URLSearchParams(window.location.search);if(params.get('source')==='website'){setSource('website');setFiltersOpen(true);}if(params.get('status')==='demande')setStatus('demande');},[]);
  function filterStatus(value: string) { setTrash(false);setStatus(value); setPage(1); }
  function resetFilters() { setQ(''); setStatus(''); setVehicle(''); setSource(''); setPage(1); }
  const filtered = Boolean(search || status || vehicle || source);
  const [continueCreated,setContinueCreated]=useState<number|null>(null);
  const [creating, setCreating] = useState(false), [opened, setOpened] = useState<number | null>(null);
  useEffect(()=>{const url=new URL(window.location.href);if(url.searchParams.get('new')==='1'&&!url.searchParams.has('dossier')&&!url.searchParams.has('create_for')){setCreating(true);url.searchParams.delete('new');window.history.replaceState({},'',url);}},[]);
  const [view, setView] = useState<'planning' | 'requests'>('requests'), [seed, setSeed] = useState<RequestSeed | undefined>();
  const [initialClient,setInitialClient]=useState<Client|undefined>();
  const [clientSeedError,setClientSeedError]=useState(''),[clientSeedLoading,setClientSeedLoading]=useState(false),[clientSeedRetry,setClientSeedRetry]=useState(0);
  function clearClientSeed(){setInitialClient(undefined);setClientSeedError('');const url=new URL(window.location.href);url.searchParams.delete('create_for');window.history.replaceState({},'',url);}
  useEffect(()=>{
    const params=new URLSearchParams(window.location.search),value=params.get('create_for');
    if(!value||params.has('dossier'))return;
    if(!/^[1-9][0-9]*$/.test(value)||!Number.isSafeInteger(Number(value))){setClientSeedError('Le lien du client est invalide. Retournez à sa fiche.');return;}
    const controller=new AbortController();setClientSeedLoading(true);setClientSeedError('');
    fetch(api+'/api/v1/customers/'+value,{credentials:'include',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([controller.signal,AbortSignal.timeout(10000)])}).then(async response=>{if(!response.ok)throw Error('Impossible de charger ce client. Réessayez ou retournez à sa fiche.');return response.json();}).then(body=>{if(!controller.signal.aborted){setInitialClient(body.data);setSeed(undefined);setCreating(true);}}).catch(e=>{if(!controller.signal.aborted)setClientSeedError(e.message);}).finally(()=>{if(!controller.signal.aborted)setClientSeedLoading(false);});
    return()=>controller.abort();
  },[clientSeedRetry]);
  useEffect(()=>{const read=()=>{const value=new URLSearchParams(window.location.search).get('dossier');return value&&/^\d+$/.test(value)?Number(value):null;};setOpened(read());let restoring=false;const back=()=>{if(restoring){restoring=false;return;}const event=new Event('dossier-before-leave',{cancelable:true});if(!window.dispatchEvent(event)){restoring=true;window.history.go(1);return;}setOpened(read());};window.addEventListener('popstate',back);return()=>window.removeEventListener('popstate',back);},[]);
  useEffect(()=>{if(new URLSearchParams(window.location.search).get('view')==='planning')setView('planning');},[]);
  function openFile(id:number, tab?:string){const url=new URL(window.location.href);url.searchParams.set('dossier',String(id));url.searchParams.delete('etape');if(tab)url.searchParams.set('tab',tab);else url.searchParams.delete('tab');window.history.pushState({},'',url);setOpened(id);window.scrollTo(0,0);}
  function closeFile(){const url=new URL(window.location.href);url.searchParams.delete('dossier');url.searchParams.delete('etape');url.searchParams.delete('tab');window.history.replaceState({},'',url);setOpened(null);setRevision(n=>n+1);}

  useEffect(() => { const timer = setTimeout(() => { setSearch(q.trim()); setPage(1); }, 250); return () => clearTimeout(timer); }, [q]);
  useEffect(() => {
    const controller = new AbortController(); setBusy(true); setError('');
    void (async () => {
      try {
        const response = await fetch(`${api}/api/v1/reservations?` + new URLSearchParams({q: search, page: String(page), ...(trash?{trash:"1"}:{}), ...(status&&!trash ? {status} : {}), ...(vehicle ? {vehicle_id: vehicle} : {}), ...(source ? {source} : {}), sort}), {credentials: 'include', cache: 'no-store', signal: AbortSignal.any([controller.signal, AbortSignal.timeout(10000)])});
        if (response.status === 401) { window.location.replace('/login'); return; }
        const body = await response.json();
        if (!response.ok) throw Error(body.message ?? 'Chargement impossible. Réessayez.');
        if (!controller.signal.aborted) setData(body);
      } catch (e) { if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'Connexion impossible.'); }
      finally { if (!controller.signal.aborted) setBusy(false); }
    })();
    return () => controller.abort();
  }, [search, page, revision, status, vehicle, source, sort, trash]);

  useEffect(()=>{const timer=setInterval(()=>{if(document.visibilityState==='visible'&&opened===null)setRevision(n=>n+1);},30000);return()=>clearInterval(timer);},[opened]);
  if(opened!==null)return <RequestFile key={opened} id={opened} startAtPrice={continueCreated===opened} onUpdated={()=>{setRevision(n=>n+1);refreshActivity();}} onClose={closeFile}/>;
  return <section className="rq-page">
    <header className="rq-page-heading"><div><span className="rq-eyebrow">RÉSERVATIONS / PLANNING</span><h1>Réservations</h1><p>Retrouvez une location ou préparez un nouveau départ.</p></div><button className="primary" disabled={clientSeedLoading} onClick={() => {clearClientSeed();setSeed(undefined); setCreating(true);}}><Plus size={18}/>Nouvelle demande</button></header>
    {clientSeedLoading&&<p role="status">Chargement du client…</p>}
    {clientSeedError&&<div className="notice error" role="alert"><p>{clientSeedError}</p><button className="secondary" onClick={()=>setClientSeedRetry(n=>n+1)}>Réessayer</button> <a href="/clients">Retour aux clients</a></div>}
    <nav className="rq-view-switch" aria-label="Vues des réservations"><button aria-pressed={view === 'requests'} onClick={() => setView('requests')}><FileText size={17}/>Dossiers de location</button><button aria-pressed={view === 'planning'} onClick={() => setView('planning')}><CalendarDays size={17}/>Planning des voitures</button></nav>
    {view === 'planning' ? <FleetPlanning revision={revision} onOpen={openFile} onCreate={initial => {clearClientSeed();setSeed(initial); setCreating(true);}}/> : <>

    {notice&&<p role="status" className="request-disposal-notice">{notice}</p>}
    {!trash&&<div className="rq-summary" aria-label="Résumé des dossiers filtrés">
      {([['demande', 'Demandes à traiter', FileText], ['confirmee', 'Départs à préparer', CalendarDays], ['en_location', 'Voitures en location', CarFront], ['retournee', 'Retours enregistrés', ArrowDownLeft]] as const).map(([key, label, Icon]) => <button key={key} className={`rq-summary-item ${key}`} aria-pressed={!trash&&status === key} onClick={() => filterStatus(status === key ? '' : key)}><span className="rq-summary-icon"><Icon size={21}/></span><span><span>{label}</span><strong>{busy || error ? '—' : data?.counts[key] ?? 0}</strong></span></button>)}
    </div>}
    <nav className="rq-status-tabs" aria-label="Filtrer par état"><button aria-pressed={!status&&!trash} onClick={() => filterStatus('')}>Tous <span>{busy || error ? '—' : data?.matching_total ?? 0}</span></button>{states.map(([key, label]) => <button key={key} aria-pressed={!trash&&status === key} onClick={() => filterStatus(key)}><i className={key}/>{label}<span>{busy || error ? '—' : data?.counts[key] ?? 0}</span></button>)}{data?.can_manage_requests&&<button aria-pressed={trash} onClick={()=>{setTrash(true);setStatus('');setPage(1);setNotice('');}}>Corbeille</button>}</nav>
    {trash&&<p className="request-trash-note">Demandes retirées des listes. La restauration conserve leur historique et ne réserve aucune voiture.</p>}
    <section className="rq-list"  aria-label="Répertoire des demandes">
      <header><div className="rq-list-heading"><h2>{trash?'Corbeille':'Vos locations'} <span aria-label="Nombre de résultats">{busy || error ? '—' : data?.total ?? 0}</span></h2><button className="secondary rq-refresh" aria-label="Actualiser les demandes" disabled={busy} onClick={() => setRevision(n => n + 1)}><RefreshCw size={15}/>Actualiser</button></div><div className="rq-list-tools" data-filters-open={filtersOpen}><div className="rq-search"><Search size={18}/><label className="sr-only" htmlFor="rq-search">Rechercher une demande</label><input data-validation-field="q" id="rq-search" placeholder="Client, téléphone, plaque ou référence…" value={q} maxLength={100} onChange={e => setQ(e.target.value)}/>{q && <button className="rq-clear-search" aria-label="Effacer la recherche" onClick={() => setQ('')}><X size={16}/></button>}<FieldHelp field="request_search" label="Rechercher une demande"/></div><button className="rq-mobile-filters secondary" aria-expanded={filtersOpen} onClick={() => setFiltersOpen(value => !value)}>{filtersOpen ? 'Masquer les filtres' : 'Voiture, origine et tri'}<ChevronRight size={16}/></button><label className="rq-filter"><span>Voiture</span><select data-validation-field="vehicle" aria-label="Voiture" value={vehicle} onChange={e => {setVehicle(e.target.value); setPage(1);}}><option value="">Toutes les voitures</option>{data?.vehicles.map(car => <option key={car.id} value={car.id}>{car.make} {car.model} · {car.plate}</option>)}</select></label><label className="rq-filter"><span>Origine</span><select data-validation-field="source" aria-label="Origine" value={source} onChange={e => {setSource(e.target.value); setPage(1);}}><option value="">Toutes les origines</option><option value="website">Site web</option><option value="agency">Agence</option></select></label><label className="rq-filter"><span>Trier par</span><select data-validation-field="sort" aria-label="Trier par" value={sort} onChange={e => {setSort(e.target.value); setPage(1);}}><option value="newest">Plus récentes</option><option value="departure">Date de départ</option></select></label></div>{filtered && <button className="rq-reset" onClick={resetFilters}><X size={14}/>Réinitialiser les filtres</button>}<small className="rq-count-note">Les compteurs suivent la recherche, la voiture et l’origine sélectionnées.</small></header>
      {error ? <div className="rq-empty" role="alert"><p>{error}</p><button className="secondary" onClick={() => setRevision(n => n + 1)}><RefreshCw size={16}/>Réessayer</button></div> : busy ? <p className="rq-empty" role="status">Chargement des demandes…</p> : !data?.data.length ? <div className="rq-empty"><CalendarDays size={30}/><h3>{trash ? 'La corbeille est vide.' : filtered ? 'Aucune demande trouvée.' : 'Votre première demande commence ici.'}</h3><p>{trash ? 'Les demandes supprimées apparaîtront ici, avec une action pour les restaurer.' : filtered ? 'Modifiez vos filtres ou votre recherche.' : 'Choisissez un client, ses dates et une voiture pour préparer son dossier.'}</p><button className="secondary" onClick={() => trash ? setTrash(false) : filtered ? resetFilters() : setCreating(true)}>{trash ? 'Retour aux demandes' : filtered ? 'Réinitialiser les filtres' : 'Créer une demande'}</button></div> : <>
        <div className="rq-table-wrap"><table className="rq-table rq-directory-table"><caption className="sr-only">Dossiers de location. Cliquez sur une ligne ou utilisez Ouvrir pour consulter le dossier. Heures du Maroc.</caption><thead><tr><th scope="col">Réservation</th><th scope="col">Client</th><th scope="col">Voiture</th><th scope="col">Séjour</th><th scope="col">Total location</th><th scope="col">Statut</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead><tbody>{data.data.map(row => {
          const operation = row.status === 'en_location' ? {Icon: Flag, label: 'Préparer le retour', tab: 'operations'} : row.status === 'confirmee' ? {Icon: KeyRound, label: 'Préparer la remise des clés', tab: 'operations'} : row.status === 'demande' || row.status === 'temporaire' ? {Icon: Check, label: 'Examiner le paiement et la confirmation', tab: 'payments'} : null;
          return <tr key={row.id} data-status={row.status} onClick={e => {if (!trash&&!(e.target as HTMLElement).closest('a,button,input,select')) openFile(row.id);}}>
          <td className="rq-booking-cell">{trash?<span className="rq-reference">{row.reference}</span>:<a href={`/reservations?dossier=${row.id}`} className="rq-reference" aria-label={`Ouvrir ${row.reference}`} onClick={e => {if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;e.preventDefault();if(!trash)openFile(row.id);}}>{row.reference}</a>}<span className="rq-origin">{row.source === 'website' ? 'Site web' : 'Agence'}</span><small className="rq-created">Reçue le {new Intl.DateTimeFormat('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric',timeZone:'Africa/Casablanca'}).format(new Date(row.created_at))}</small></td>
          <td className="rq-client-cell"><div className="rq-customer"><span className="rq-avatar" aria-hidden="true">{row.customer.first_name.charAt(0).toLocaleUpperCase('fr-FR')}</span><div><strong className="rq-client-name">{row.customer.first_name} {row.customer.last_name}</strong><span className="rq-client-phone">{row.customer.phone}</span></div></div></td>
          <td className="rq-vehicle-cell"><div className="rq-table-car"><VehicleThumbnail key={row.vehicle.cover_id} row={row}/><div><strong>{row.vehicle.make} {row.vehicle.model}</strong><span className="rq-table-plate">{row.vehicle.plate}</span></div></div></td>
          <td className="rq-trip-cell"><strong>{requestedDay(row.starts_local)} → {requestedDay(row.ends_local)}</strong><span>{row.starts_local.slice(11,16)} → {row.ends_local.slice(11,16)} · {duration(row.duration_minutes)}</span>{row.overdue && <small className="rq-overdue">Retour en retard</small>}</td>
          <td className="rq-total-cell"><span className="rq-mobile-label">Total location</span><strong>{row.total_centimes == null ? 'À définir' : rentalMoney(row.total_centimes)}</strong></td>
          <td className="rq-status-cell"><span className={`rq-status-badge ${row.status}`}>{trash?'Dans la corbeille':statusLabels[row.status]}</span>{row.hold_expired && <small>Délai expiré</small>}</td>
          <td className="rq-actions-cell"><div className="rq-row-actions">{!trash&&operation && <button type="button" title={operation.label} aria-label={`${operation.label} · ${row.reference}`} onClick={() => openFile(row.id,operation.tab)}><operation.Icon size={17}/></button>}{!trash&&row.customer.phone && <a href={`tel:${row.customer.phone.replace(/[^+0-9]/g,'')}`} title="Appeler le client" aria-label={`Appeler ${row.customer.first_name} ${row.customer.last_name}`}><Phone size={17}/></a>}{!trash&&<button type="button" title="Voir le dossier" aria-label={`Voir le dossier ${row.reference}`} onClick={() => openFile(row.id)}><Eye size={18}/></button>}{data.can_manage_requests&&(trash||row.status==='demande'||row.status==='annulee')&&<RequestActions id={row.id} reference={row.reference} trash={trash} onOpen={()=>openFile(row.id,'payments')} onDone={(message,action)=>{if(action==='cancel_quick')filterStatus('demande');setNotice(message);setRevision(n=>n+1);refreshActivity();}}/>}</div></td>
        </tr>})}</tbody></table></div>
        <footer><span>{data.total} {search ? 'résultat(s)' : 'demande(s)'} · Page {data.page} sur {data.last_page}</span><div><button className="secondary" aria-label="Demandes précédentes" disabled={data.page <= 1} onClick={() => setPage(data.page - 1)}><ChevronLeft size={18}/></button><button className="secondary" aria-label="Demandes suivantes" disabled={data.page >= data.last_page} onClick={() => setPage(data.page + 1)}><ChevronRight size={18}/></button></div></footer>
      </>}
    </section>
    <p className="rq-page-footnote"><Clock3 size={14}/>Les dates et heures sont affichées à l’heure du Maroc.</p>
    </>}
    {creating && <RequestForm initial={seed} initialClient={initialClient} onClose={() => {setCreating(false); setSeed(undefined);clearClientSeed();}} onSaved={id => {clearClientSeed();setContinueCreated(id);setCreating(false); setSeed(undefined); openFile(id); setRevision(n => n + 1);}}/>}

  </section>;
}
