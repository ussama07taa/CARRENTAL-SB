'use client';
import {rentalMoney} from './rental-payment';

import {useEffect, useState} from 'react';
import {ArrowDownLeft, ArrowUpRight, CalendarDays, CarFront, ChevronLeft, ChevronRight, Clock3, FileText, Plus, RefreshCw, Search, X} from 'lucide-react';
import {api} from '../_lib/auth-client';
import FieldHelp from '../_components/field-help';
import {ReservationState} from './temporary-hold';
import RequestFile from './request-file';
import RequestForm, {type RequestSeed} from './request-form';
import FleetPlanning from './fleet-planning';
import {duration, type RentalRequest} from './request-types';
import './request-list.css';

type Listing = {data: RentalRequest[]; total: number; page: number; last_page: number};
const requestedDay = (value: string) => new Intl.DateTimeFormat('fr-FR', {day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC'}).format(new Date(value + 'Z'));

function RequestedDate({value, kind}: {value: string; kind: 'departure' | 'return'}) {
  return <div className={`rq-date-cell ${kind}`}>
    <span className="rq-mobile-label">{kind === 'departure' ? 'Départ souhaité' : 'Retour souhaité'}</span>
    <strong>{requestedDay(value)}</strong>
    <span className="rq-time"><Clock3 size={14}/>{value.slice(11, 16)}</span>
  </div>;
}

export default function Requests() {
  const [data, setData] = useState<Listing | null>(null), [q, setQ] = useState(''), [search, setSearch] = useState('');
  const [page, setPage] = useState(1), [busy, setBusy] = useState(true), [error, setError] = useState(''), [revision, setRevision] = useState(0);
  const [creating, setCreating] = useState(false), [opened, setOpened] = useState<number | null>(null);
  const [view, setView] = useState<'planning' | 'requests'>('planning'), [seed, setSeed] = useState<RequestSeed | undefined>();
  useEffect(()=>{const read=()=>{const value=new URLSearchParams(window.location.search).get('dossier');return value&&/^\d+$/.test(value)?Number(value):null;};setOpened(read());let restoring=false;const back=()=>{if(restoring){restoring=false;return;}const event=new Event('dossier-before-leave',{cancelable:true});if(!window.dispatchEvent(event)){restoring=true;window.history.go(1);return;}setOpened(read());};window.addEventListener('popstate',back);return()=>window.removeEventListener('popstate',back);},[]);
  function openFile(id:number){const url=new URL(window.location.href);url.searchParams.set('dossier',String(id));url.searchParams.delete('etape');window.history.pushState({},'',url);setOpened(id);window.scrollTo(0,0);}
  function closeFile(){const url=new URL(window.location.href);url.searchParams.delete('dossier');url.searchParams.delete('etape');window.history.replaceState({},'',url);setOpened(null);setRevision(n=>n+1);}

  useEffect(() => { const timer = setTimeout(() => { setSearch(q.trim()); setPage(1); }, 250); return () => clearTimeout(timer); }, [q]);
  useEffect(() => {
    const controller = new AbortController(); setBusy(true); setError('');
    void (async () => {
      try {
        const response = await fetch(`${api}/api/v1/reservations?` + new URLSearchParams({q: search, page: String(page)}), {credentials: 'include', cache: 'no-store', signal: AbortSignal.any([controller.signal, AbortSignal.timeout(10000)])});
        if (response.status === 401) { window.location.replace('/login'); return; }
        const body = await response.json();
        if (!response.ok) throw Error(body.message ?? 'Chargement impossible. Réessayez.');
        if (!controller.signal.aborted) setData(body);
      } catch (e) { if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'Connexion impossible.'); }
      finally { if (!controller.signal.aborted) setBusy(false); }
    })();
    return () => controller.abort();
  }, [search, page, revision]);

  useEffect(()=>{const timer=setInterval(()=>{if(document.visibilityState==='visible'&&opened===null)setRevision(n=>n+1);},30000);return()=>clearInterval(timer);},[opened]);
  if(opened!==null)return <RequestFile key={opened} id={opened} onUpdated={()=>setRevision(n=>n+1)} onClose={closeFile}/>;
  return <section className="rq-page">
    <header className="rq-page-heading"><div><span className="rq-eyebrow">RÉSERVATIONS / PLANNING</span><h1>Planning des véhicules</h1><p>Repérez une voiture, consultez ses dates, puis préparez la demande.</p></div><button className="primary" onClick={() => {setSeed(undefined); setCreating(true);}} disabled={!!error}><Plus size={18}/>Nouvelle demande</button></header>
    <nav className="rq-view-switch" aria-label="Vues des réservations"><button aria-pressed={view === 'planning'} onClick={() => setView('planning')}><CalendarDays size={17}/>Planning du parc</button><button aria-pressed={view === 'requests'} onClick={() => setView('requests')}><FileText size={17}/>Demandes</button></nav>
    {view === 'planning' ? <FleetPlanning revision={revision} onOpen={openFile} onCreate={initial => {setSeed(initial); setCreating(true);}}/> : <>
    <div className="rq-explainer"><FileText size={22}/><div><strong>Enregistrer une demande, c’est préparer la location.</strong><p>Une demande ne bloque pas la voiture. Depuis son dossier, activez une réservation temporaire de 2 heures après accord du client.</p></div><span className="rq-badge">Blocage depuis le dossier</span></div>
    <section className="rq-list" aria-label="Répertoire des demandes">
      <header><div className="rq-list-heading"><h2>Dossiers enregistrés <span aria-label="Nombre de résultats">{busy || error ? '—' : data?.total ?? 0}</span></h2><button className="secondary rq-refresh" aria-label="Actualiser les demandes" disabled={busy} onClick={() => setRevision(n => n + 1)}><RefreshCw size={15}/>Actualiser</button></div><div className="rq-list-tools"><div className="rq-search"><Search size={18}/><label className="sr-only" htmlFor="rq-search">Rechercher une demande</label><input id="rq-search" placeholder="Client, téléphone, plaque ou référence…" value={q} maxLength={100} onChange={e => setQ(e.target.value)}/>{q && <button className="rq-clear-search" aria-label="Effacer la recherche" onClick={() => setQ('')}><X size={16}/></button>}<FieldHelp field="request_search" label="Rechercher une demande"/></div><span className="rq-sort-label"><Clock3 size={14}/>Plus récentes en premier</span></div></header>
      {error ? <div className="rq-empty" role="alert"><p>{error}</p><button className="secondary" onClick={() => setRevision(n => n + 1)}><RefreshCw size={16}/>Réessayer</button></div> : busy ? <p className="rq-empty" role="status">Chargement des demandes…</p> : !data?.data.length ? <div className="rq-empty"><CalendarDays size={30}/><h3>{search ? 'Aucune demande trouvée.' : 'Votre première demande commence ici.'}</h3><p>{search ? 'Essayez un autre nom ou une autre plaque.' : 'Choisissez un client, ses dates et une voiture pour préparer son dossier.'}</p><button className="secondary" onClick={() => search ? setQ('') : setCreating(true)}>{search ? 'Effacer la recherche' : 'Créer une demande'}</button></div> : <>
        <div className="rq-table-wrap"><table className="rq-table"><caption className="sr-only">Demandes de location, de la plus récente à la plus ancienne. Les demandes ne réservent pas la voiture. Dates et heures du Maroc.</caption><thead><tr><th scope="col">Demande / client</th><th scope="col">Voiture demandée</th><th scope="col"><span className="rq-th-departure"><ArrowUpRight size={16}/>Départ souhaité</span></th><th scope="col"><span className="rq-th-return"><ArrowDownLeft size={16}/>Retour souhaité</span></th><th scope="col">Durée</th><th scope="col">Suivi du dossier</th></tr></thead><tbody>{data.data.map(row => <tr key={row.id}>
          <td className="rq-client-cell"><span className="rq-reference"><FileText size={13}/>{row.reference}</span><strong className="rq-client-name">{row.customer.first_name} {row.customer.last_name}</strong><span className="rq-client-phone">{row.customer.phone}</span></td>
          <td className="rq-vehicle-cell"><span className="rq-mobile-label">Voiture demandée</span><div className="rq-table-car"><span className="rq-table-icon"><CarFront size={23}/></span><div><strong>{row.vehicle.make} {row.vehicle.model}</strong><span className="rq-table-plate">{row.vehicle.plate}</span></div></div></td>
          <td className="rq-departure-cell"><RequestedDate value={row.starts_local} kind="departure"/></td>
          <td className="rq-return-cell"><RequestedDate value={row.ends_local} kind="return"/></td>
          <td className="rq-duration-cell"><span className="rq-mobile-label">Durée souhaitée</span><strong className="rq-duration">{duration(row.duration_minutes)}</strong></td>
          <td className="rq-followup-cell"><ReservationState row={row}/>{row.account&&!row.account.settled&&<small>Règlement à suivre · client : {rentalMoney(row.account.remaining_centimes)} · crédit : {rentalMoney(row.account.credit_centimes)} · caution : {rentalMoney(row.account.deposit_held_centimes)} · en attente : {rentalMoney(row.account.pending_centimes)}</small>}<button className="secondary rq-open-file" aria-label={`Ouvrir ${row.reference}`} onClick={() => openFile(row.id)}>Voir le dossier <ArrowUpRight size={16}/></button></td>
        </tr>)}</tbody></table></div>
        <footer><span>{data.total} {search ? 'résultat(s)' : 'demande(s)'} · Page {data.page} sur {data.last_page}</span><div><button className="secondary" aria-label="Demandes précédentes" disabled={data.page <= 1} onClick={() => setPage(data.page - 1)}><ChevronLeft size={18}/></button><button className="secondary" aria-label="Demandes suivantes" disabled={data.page >= data.last_page} onClick={() => setPage(data.page + 1)}><ChevronRight size={18}/></button></div></footer>
      </>}
    </section>
    <p className="rq-page-footnote"><Clock3 size={14}/>Les dates et heures sont affichées à l’heure du Maroc.</p>
    </>}
    {creating && <RequestForm initial={seed} onClose={() => {setCreating(false); setSeed(undefined);}} onSaved={id => {setCreating(false); setSeed(undefined); openFile(id); setRevision(n => n + 1);}}/>}

  </section>;
}
