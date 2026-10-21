'use client';
import {useCreateShortcut} from '../_lib/use-create-shortcut';
import {validateFields,focusFieldError} from '../_lib/form-validation';
import FieldHelp from '../_components/field-help';

import { useCallback,useEffect, useRef, useState, type FormEvent } from 'react';
import { api, apiMutation } from '../_lib/auth-client';
import PhotoTile, { DocumentIcon, type DocumentSlot, type CustomerDocument, type PendingPhotos } from './document-upload';

type Fields = {
  first_name: string; last_name: string; phone: string; secondary_phone: string;
  email: string; city: string; address: string; language: string;
  identity_type: string; identity_number: string; licence_number: string; licence_expires_on: string;
};
type Customer = Fields & { id: number; reference: string; version: number; created_at: string; updated_at: string };
type Activity = { action: string; actor: string; created_at: string };
type Listing = { data: Customer[]; total: number; page: number; last_page: number };
type Conflict = { code?: string; message: string; customer_id?: number; duplicates?: Customer[] };
const empty: Fields = { first_name: '', last_name: '', phone: '', secondary_phone: '', email: '', city: '', address: '', language: 'fr', identity_type: '', identity_number: '', licence_number: '', licence_expires_on: '' };
const date = (value: string) => new Intl.DateTimeFormat('fr-MA', { dateStyle: 'medium', timeZone: 'Africa/Casablanca' }).format(new Date(value));
const initials = (c: Fields) => (c.first_name[0] ?? '') + (c.last_name[0] ?? '');
function values(c: Customer): Fields {
  return Object.fromEntries(Object.keys(empty).map(key => [key, c[key as keyof Fields] ?? ''])) as Fields;
}
function requireSession(response: Response) {
  if (response.status === 401 || response.status === 403) {
    window.location.replace('/login');
    throw new Error('Votre session a expiré. Reconnectez-vous.');
  }
}

export default function Customers() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reload, setReload] = useState(0);
  const [listing, setListing] = useState<Listing | null>(null);
  const [loading, setLoading] = useState(true);
  const [listError, setListError] = useState('');
  const [flash, setFlash] = useState('');
  const [opened, setOpened] = useState(false);
  const [mode, setMode] = useState<'view' | 'edit' | 'create'>('view');
  const [section, setSection] = useState<'information' | 'documents'>('information');
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [activity, setActivity] = useState<Activity[]>([]);
  const [documents, setDocuments] = useState<CustomerDocument[]>([]);
  const [pendingPhotos, setPendingPhotos] = useState<PendingPhotos>({});
  const [checkingPhotos,setCheckingPhotos]=useState<Partial<Record<DocumentSlot,boolean>>>({});
  const photoChecking=useCallback((slot:DocumentSlot,checking:boolean)=>setCheckingPhotos(current=>current[slot]===checking?current:{...current,[slot]:checking}),[]);
  const photosChecking=Object.values(checkingPhotos).some(Boolean);
  const [canManageDocuments, setCanManageDocuments] = useState(true);
  const [saveProgress, setSaveProgress] = useState('');
  const [form, setForm] = useState<Fields>({ ...empty });
  const [baseline, setBaseline] = useState<Fields>({ ...empty });
  const [key, setKey] = useState('');
  const [saving, setSaving] = useState(false);
  const [opening, setOpening] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [conflict, setConflict] = useState<Conflict | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const saveLock = useRef(false);
  const openSequence = useRef(0);
  const dirty = mode !== 'view' && (JSON.stringify(form) !== JSON.stringify(baseline) || Object.keys(pendingPhotos).length > 0 || photosChecking);

  useEffect(() => { const id=Number(new URLSearchParams(window.location.search).get('customer')); if(Number.isSafeInteger(id)&&id>0)void openCustomer(id); }, []);

  useEffect(() => { dialog.current?.querySelector('.drawer-body')?.scrollTo({ top: 0 }); }, [section]);

  useEffect(() => {
    const abort = new AbortController();
    setLoading(true); setListError('');
    const timer = setTimeout(async () => {
      try {
      const response = await fetch(api + '/api/v1/customers?' + new URLSearchParams({ q: search, page: String(page) }), { credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.any([abort.signal, AbortSignal.timeout(10000)]) });
        requireSession(response);
        if (!response.ok) throw new Error('La liste n’a pas pu être chargée.');
        const data: Listing = await response.json();
        if (!abort.signal.aborted) setListing(data);
      } catch (e) {
        if (!abort.signal.aborted) { setListing(null); setListError(e instanceof Error ? e.message : 'Connexion impossible.'); }
      } finally { if (!abort.signal.aborted) setLoading(false); }
    }, 220);
    return () => { clearTimeout(timer); abort.abort(); };
  }, [search, page, reload]);

  useEffect(() => {
    if (opened && !dialog.current?.open) dialog.current?.showModal();
    if (!opened && dialog.current?.open) dialog.current.close();
  }, [opened]);
  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => { if (dirty || saving) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty, saving]);

  function mayLeave() { return !dirty || window.confirm('Vos modifications ne sont pas enregistrées. Les abandonner ?'); }
  function close() {
    if (saving || !mayLeave()) return;
    openSequence.current++; setOpened(false); setMode('view'); setError(''); setConflict(null); setPendingPhotos({});
  }
  useCreateShortcut(create);
  function create() {
    setSection('information');
    setCustomer(null); setActivity([]); setDocuments([]); setPendingPhotos({}); setCanManageDocuments(true); setForm({ ...empty }); setBaseline({ ...empty });
    setMode('create'); setKey(crypto.randomUUID()); setErrors({}); setError(''); setConflict(null); setFlash('');
    setOpening(false); setOpened(true);
  }
  async function openCustomer(id: number, bypassWarning = false) {
    if (saving || (!bypassWarning && !mayLeave())) return;
    const sequence = ++openSequence.current;
    setSection('information');
    setOpened(true); setOpening(true); setMode('view'); setCustomer(null); setPendingPhotos({}); setDocuments([]); setConflict(null); setError(''); setErrors({}); setFlash('');
    try {
      const response = await fetch(api + '/api/v1/customers/' + id, { credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000) });
      requireSession(response);
      if (!response.ok) throw new Error(response.status === 404 ? 'Ce fichier est introuvable.' : 'Le fichier n’a pas pu être chargé. Fermez puis réessayez.');
      const data = await response.json();
      if (sequence === openSequence.current) {
        setCustomer(data.data); setActivity(data.activity); setDocuments(data.documents ?? []); setCanManageDocuments(data.can_manage_documents === true);
        setForm(values(data.data)); setBaseline(values(data.data));
      }
    } catch (e) { if (sequence === openSequence.current) setError(e instanceof Error ? e.message : 'Connexion impossible.'); }
    finally { if (sequence === openSequence.current) setOpening(false); }
  }
  function edit() { if (!customer) return; setForm(values(customer)); setBaseline(values(customer)); setMode('edit'); setError(''); setConflict(null); setErrors({}); }
  function cancelEdit() {
    if (!mayLeave()) return;
    setMode('view'); setError(''); setErrors({}); setConflict(null); setPendingPhotos({});
  }
  async function save(acknowledge = false) {
    if(photosChecking){setError('Attendez la vérification de l’aperçu avant d’enregistrer.');return;}
    if (saveLock.current || !formRef.current) return;
    const invalid=validateFields(formRef.current);
    if(Object.keys(invalid).length){setErrors(invalid);setError('Vérifiez les champs indiqués ci-dessous.');setSection(['identity_type','identity_number','licence_number','licence_expires_on'].includes(Object.keys(invalid)[0])?'documents':'information');setTimeout(()=>focusFieldError(formRef.current,invalid),0);return;}
    saveLock.current = true; setSaving(true); setSaveProgress('Enregistrement du fichier…'); setError(''); setErrors({}); setConflict(null);
    try {
      const response = await apiMutation(customer ? 'customers/' + customer.id : 'customers', customer ? 'PUT' : 'POST', {
        ...form, ...(customer ? { version: customer.version } : { creation_key: key }), acknowledge_duplicates: acknowledge,
      });
      requireSession(response);
      const data = await response.json();
      if (response.status === 422) {
        setErrors(data.errors ?? {}); setError('Vérifiez les champs indiqués ci-dessous.');
        setSection(['identity_type', 'identity_number', 'licence_number', 'licence_expires_on'].includes(Object.keys(data.errors ?? {})[0] ?? '') ? 'documents' : 'information');
        setTimeout(() => focusFieldError(formRef.current,data.errors??{}), 0);
        return;
      }
      if (response.status === 409) { setConflict(data); return; }
      if (!response.ok) throw new Error('Enregistrement non confirmé. Vos saisies sont conservées ; réessayez.');
      setCustomer(data.data); setForm(values(data.data)); setBaseline(values(data.data));
      const remaining = { ...pendingPhotos };
      const failures: string[] = [];
      let nextDocuments = [...documents];
      const photos = Object.entries(pendingPhotos) as [DocumentSlot, NonNullable<PendingPhotos[DocumentSlot]>][];
      for (let i = 0; i < photos.length; i++) {
        const [slot, photo] = photos[i];
        setSaveProgress(`Envoi de la photo ${i + 1} / ${photos.length}…`);
        try {
          const upload = new FormData();
          upload.append('image', photo.file); upload.append('slot', slot); upload.append('upload_key', photo.key); upload.append('previous_id', photo.previousId === null ? '' : String(photo.previousId));
          const result = await apiMutation('customers/' + data.data.id + '/documents', 'POST', upload);
          if (result.status === 401) requireSession(result);
          const payload = await result.json();
          if (!result.ok) throw new Error(result.status === 422 ? payload.errors?.image?.[0] ?? 'Vérifiez le format et la taille de la photo.' : result.status === 409 ? payload.message : result.status === 403 ? 'L’accès aux photos est réservé au responsable pour cette étape.' : result.status === 429 ? 'Trop d’envois rapprochés. Réessayez dans une minute.' : 'Envoi non confirmé. Réessayez.');
          nextDocuments = [...nextDocuments.filter(item => item.slot !== slot), payload.data];
          delete remaining[slot];
        } catch (uploadError) {const message=uploadError instanceof Error ? uploadError.message : 'Envoi non confirmé. Réessayez.';failures.push(message);setErrors(v=>({...v,['photo_'+slot]:[message]})); }
      }
      setDocuments(nextDocuments); setPendingPhotos(remaining);
      if (failures.length) {
        setSection('documents');setMode('edit'); setError('Les informations du client sont enregistrées, mais certaines photos ne le sont pas. ' + failures.join(' ')); setReload(n => n + 1);
        setTimeout(() => dialog.current?.querySelector('.drawer-message.error')?.scrollIntoView({ block: 'center' }), 0);
        return;
      }
      setMode('view');
      setActivity([{ action: customer ? 'updated' : 'created', actor: 'Vous', created_at: data.data.updated_at }, ...activity]);
      setFlash(photos.length ? 'Le fichier et les photos sont enregistrés.' : customer ? 'Les modifications sont enregistrées.' : 'Le client est créé. Son fichier est prêt à être complété.');
      setReload(n => n + 1);
    } catch (e) { setError(e instanceof Error ? e.message : 'Enregistrement non confirmé. Réessayez.'); }
    finally { saveLock.current = false; setSaving(false); setSaveProgress(''); }
  }
  function submit(e: FormEvent) { e.preventDefault(); void save(); }
  function field(name: keyof Fields, label: string, type = 'text', required = false, hint?: string) {
    const max = ({ first_name: 100, last_name: 100, phone: 30, secondary_phone: 30, email: 254, city: 100, address: 500, identity_number: 50, licence_number: 50 } as Partial<Record<keyof Fields, number>>)[name];
    return <div className={'client-field ' + (name === 'address' ? 'span-two' : '')}>
      <label htmlFor={'client-' + name}>{label}{required && <span className="required-dot" aria-label="obligatoire"> *</span>}<FieldHelp field={name} label={label}/></label>
      <input aria-label={label} id={'client-' + name} name={name} value={form[name]} type={type} required={required} maxLength={max} autoComplete={name === 'first_name' ? 'given-name' : name === 'last_name' ? 'family-name' : name === 'phone' ? 'tel' : name === 'email' ? 'email' : 'off'} aria-invalid={!!errors[name]?.length} aria-describedby={errors[name]?.length || hint ? 'help-' + name : undefined}
        onChange={e => { setForm({ ...form, [name]: e.target.value }); setConflict(null); setErrors(prev => ({ ...prev, [name]: [] })); }} />
      {(errors[name]?.length > 0 || hint) && <small id={'help-' + name} className={errors[name]?.length ? 'field-error' : ''}>{errors[name]?.[0] ?? hint}</small>}
    </div>;
  }
  function detail(label: string, value: string | undefined | null) {
    return <div><dt>{label}</dt><dd className={value ? '' : 'missing-value'}>{value || 'Non renseigné'}</dd></div>;
  }

  function photo(slot: DocumentSlot, label: string) {
    return <PhotoTile key={slot} slot={slot} label={label} serverError={errors['photo_'+slot]?.[0]} customerId={customer?.id} record={documents.find(d => d.slot === slot)} pending={pendingPhotos[slot]} editable={mode !== 'view' && canManageDocuments} disabled={saving} onCheckingChange={photoChecking}
      onSelect={(target,file)=>{setErrors(v=>({...v,['photo_'+target]:[]}));setPendingPhotos(current=>({...current,[target]:{file,key:crypto.randomUUID(),previousId:documents.find(d=>d.slot===target)?.id??null}}));}}
      onRemove={target => setPendingPhotos(current => { const next = { ...current }; delete next[target]; return next; })} />;
  }

  function documentCards() {
    return <div className="document-cards">
      <div className="documents-intro"><span className="eyebrow">IDENTITÉ & DOCUMENTS</span><p>Les informations et leurs photos, réunies au même endroit.</p></div>
      <section className="document-card"><header><span className="document-card-icon"><DocumentIcon /></span><div><h3>Pièce d’identité</h3><p>Carte nationale ou passeport</p></div><span className="private-pill"><Lock /> Privé</span></header>
        {mode !== 'view' ? <div className="document-fields client-form-grid"><div className="client-field"><label htmlFor="client-identity_type">Type de pièce<FieldHelp field={"identity_type"} label={"Type de pièce"}/></label><select name="identity_type" aria-describedby={errors.identity_type?.length?"error-identity_type":undefined} aria-label="Type de pièce" id="client-identity_type" value={form.identity_type} aria-invalid={!!errors.identity_type?.length} onChange={e => setForm({ ...form, identity_type: e.target.value })}><option value="">À renseigner plus tard</option><option value="cin">Carte d’identité nationale</option><option value="passport">Passeport</option></select>{errors.identity_type?.[0] && <small id="error-identity_type" className="field-error">{errors.identity_type[0]}</small>}</div>{field('identity_number', 'Numéro de la pièce')}</div> : <dl className="document-fields profile-grid">{detail('Type de pièce', customer?.identity_type === 'cin' ? 'Carte nationale' : customer?.identity_type === 'passport' ? 'Passeport' : null)}{detail('Numéro de la pièce', customer?.identity_number)}</dl>}
        {canManageDocuments && <div className="document-photos">{photo('identity_front', form.identity_type === 'passport' ? 'Passeport · page photo' : 'Identité · recto')}{photo('identity_back', form.identity_type === 'passport' ? 'Passeport · autre page' : 'Identité · verso')}</div>}
        <p className="document-hint">{mode !== 'view' ? 'JPG, PNG ou WebP · 5 Mo maximum par photo' : 'Une photo jointe ne signifie pas que l’original a été vérifié.'}</p>
      </section>
      <section className="document-card"><header><span className="document-card-icon licence-icon"><DocumentIcon licence /></span><div><h3>Permis de conduire</h3><p>Numéro, validité et copie du permis</p></div><span className="private-pill"><Lock /> Privé</span></header>
        {mode !== 'view' ? <div className="document-fields client-form-grid">{field('licence_number', 'Numéro du permis')}{field('licence_expires_on', 'Valable jusqu’au', 'date')}</div> : <dl className="document-fields profile-grid">{detail('Numéro du permis', customer?.licence_number)}{detail('Valable jusqu’au', customer?.licence_expires_on ? date(customer.licence_expires_on) : null)}</dl>}
        {canManageDocuments && <div className="document-photos">{photo('licence_front', 'Permis · recto')}{photo('licence_back', 'Permis · verso')}</div>}
        <p className="document-hint">{mode !== 'view' ? 'Photographiez les documents à plat, sans reflet, avec les bords visibles.' : 'Les copies restent accessibles uniquement dans l’espace privé autorisé.'}</p>
      </section>
      {mode !== 'view' && <p className="document-save-note">Les photos choisies seront envoyées en cliquant sur <strong>{mode === 'create' ? 'Créer le client' : 'Enregistrer les modifications'}</strong>.</p>}
    </div>;
  }

  function sectionNavigation() {
    return <div className="file-sections" role="tablist" aria-label="Sections du fichier client" onKeyDown={event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 'information' : event.key === 'End' ? 'documents' : section === 'information' ? 'documents' : 'information';
      setSection(next); document.getElementById('section-tab-' + next)?.focus();
    }}>
      <button type="button" role="tab" id="section-tab-information" aria-selected={section === 'information'} aria-controls="section-information" tabIndex={section === 'information' ? 0 : -1} onClick={() => setSection('information')}><span className="section-number">01</span><span>Informations du client<small>Identité & coordonnées</small></span></button>
      <button type="button" role="tab" id="section-tab-documents" aria-selected={section === 'documents'} aria-controls="section-documents" tabIndex={section === 'documents' ? 0 : -1} onClick={() => setSection('documents')}><span className="section-number">02</span><span>Documents & photos<small>{Object.keys(pendingPhotos).length ? Object.keys(pendingPhotos).length + ' photo(s) à enregistrer' : documents.length ? documents.length + ' photo(s) enregistrée(s)' : 'Pièce d’identité & permis'}</small></span>{Object.keys(pendingPhotos).length > 0 && <span className="pending-indicator" aria-label="Photos non enregistrées" />}</button>
    </div>;
  }

  return <section className="clients-section">
    <div className="clients-heading"><div><p className="eyebrow">RELATION CLIENT</p><h1>Vos clients, bien suivis.</h1><p>Un seul fichier par personne. Retrouvez-le à chaque visite.</p></div><button className="primary add-client" onClick={create}><span aria-hidden="true">＋</span> Nouveau client</button></div>
    {flash && !opened && <div className="save-notice" role="status">✓ {flash}</div>}
    <section className="client-directory" aria-label="Répertoire clients">
      <div className="directory-title"><div><h2>Répertoire clients</h2><span>{loading ? 'Actualisation…' : listing ? listing.total + (listing.total === 1 ? ' fichier' : ' fichiers') + (search ? ' trouvé(s)' : ' enregistrés') : 'Liste indisponible'}</span></div><span className="privacy-label"><Lock /> Espace privé de l’agence</span></div>
      <div className="client-search"><Search /><FieldHelp field="search" label="Rechercher un client"/><input aria-label="Rechercher un client" placeholder="Nom, téléphone, pièce d’identité ou référence…" maxLength={100} value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} />{search && <button className="clear-search" aria-label="Effacer la recherche" onClick={() => { setSearch(''); setPage(1); }}>×</button>}</div>
      {listError ? <div className="directory-empty" role="alert"><h3>Connexion interrompue</h3><p>{listError}</p><button className="secondary" onClick={() => setReload(n => n + 1)}>Réessayer</button></div> :
        <div className="table-scroller" aria-busy={loading}>
          <table className="client-table"><thead><tr><th>Client</th><th>Téléphone</th><th>Ville</th><th>Dernière mise à jour</th><th><span className="sr-only">Action</span></th></tr></thead>
            <tbody>{!loading && listing?.data.map(c => <tr key={c.id}>
              <td><div className="customer-cell"><span className={'avatar avatar-' + c.id % 4} aria-hidden="true">{initials(c)}</span><div><strong>{c.first_name} {c.last_name}</strong><small>{c.reference}</small><small className="mobile-phone">{c.phone}</small></div></div></td>
              <td className="phone-cell">{c.phone}</td><td>{c.city || <span className="missing-value">À compléter</span>}</td><td className="date-cell">{date(c.updated_at)}</td>
              <td><button className="open-client" onClick={() => void openCustomer(c.id)} aria-label={'Ouvrir le fichier de ' + c.first_name + ' ' + c.last_name}>Ouvrir <span aria-hidden="true">↗</span></button></td>
            </tr>)}</tbody></table>
          {loading && <div className="directory-empty loading-state" role="status"><span className="loading-dot" /> Chargement des clients…</div>}
          {!loading && listing?.data.length === 0 && <div className="directory-empty"><span className="empty-monogram" aria-hidden="true"><Search /></span><h3>{search ? 'Aucun client ne correspond.' : 'Votre répertoire commence ici.'}</h3><p>{search ? 'Essayez un autre nom, un numéro ou une référence.' : 'Le nom et le téléphone suffisent pour créer le premier fichier.'}</p><button className="secondary" onClick={search ? () => { setSearch(''); setPage(1); } : create}>{search ? 'Effacer la recherche' : 'Ajouter un client'}</button></div>}
        </div>}
      {listing && !listError && <div className="directory-footer"><span>Les pièces d’identité restent dans le fichier du client.</span><div className="pagination"><button aria-label="Page précédente" disabled={loading || page <= 1} onClick={() => setPage(p => p - 1)}>←</button><span>{page} / {listing.last_page}</span><button aria-label="Page suivante" disabled={loading || page >= listing.last_page} onClick={() => setPage(p => p + 1)}>→</button></div></div>}
    </section>
    <div className="client-bottom-note"><span className="note-line" /><p>Enregistrez l’essentiel maintenant.<br /><strong>Complétez le fichier quand vous avez les informations.</strong></p><span className="note-index">01 / CLIENTS</span></div>

    <dialog ref={dialog} className="customer-dialog" aria-labelledby="customer-dialog-title" onCancel={e => { e.preventDefault(); close(); }} onClose={e => { if (e.target === e.currentTarget) setOpened(false); }}>
      <div className="drawer-top"><span className="eyebrow">{mode === 'create' ? 'NOUVEAU FICHIER' : customer?.reference ?? 'FICHIER CLIENT'}</span><button className="close-drawer" aria-label="Fermer le fichier" disabled={saving} onClick={close}>×</button></div>
      {opening ? <div className="drawer-body directory-empty" role="status">Ouverture du fichier…</div> : <>
        <div className="drawer-heading"><div><h2 id="customer-dialog-title">{mode === 'create' ? 'Ajouter un client' : mode === 'edit' ? 'Modifier le fichier' : customer ? customer.first_name + ' ' + customer.last_name : 'Fichier indisponible'}</h2><p>{mode === 'create' ? '3 champs pour commencer. Le reste peut attendre.' : mode === 'edit' ? 'Les changements seront conservés dans l’historique.' : 'Coordonnées, identité et historique au même endroit.'}</p></div>{mode === 'view' && customer && <span className={'avatar avatar-large avatar-' + customer.id % 4} aria-hidden="true">{initials(customer)}</span>}</div>
        {(customer || mode === 'create') && sectionNavigation()}
        {error && <div className="notice error drawer-message" role="alert">{error}</div>}
        {flash && mode === 'view' && customer && <div className="save-notice drawer-message" role="status">✓ {flash}</div>}
        {conflict && <div className="conflict-notice drawer-message" role="alert"><strong>Une vérification est nécessaire</strong><p>{conflict.message}</p>
          {conflict.duplicates?.map(c => <button className="duplicate-link" key={c.id} onClick={() => void openCustomer(c.id)}>{c.first_name} {c.last_name} · {c.phone} <span>Ouvrir ↗</span></button>)}
          {conflict.code === 'POSSIBLE_DUPLICATE' && <button className="secondary" disabled={saving} onClick={() => void save(true)}>C’est une autre personne : créer son fichier</button>}
          {conflict.customer_id && <button className="secondary" onClick={() => void openCustomer(conflict.customer_id!)}>Ouvrir le fichier existant</button>}
          {conflict.code === 'CUSTOMER_CHANGED' && customer && <button className="secondary" onClick={() => void openCustomer(customer.id)}>Recharger le fichier</button>}
        </div>}
        {mode === 'view' && customer ? <>
          <div className="drawer-body">
            <div role="tabpanel" id="section-information" aria-labelledby="section-tab-information" hidden={section !== 'information'}>
            <section className="profile-section"><h3><span>01</span> Coordonnées</h3><dl className="profile-grid">{detail('Téléphone principal', customer.phone)}{detail('Téléphone secondaire', customer.secondary_phone)}{detail('E-mail', customer.email)}{detail('Langue de communication', customer.language === 'ar' ? 'Arabe' : 'Français')}{detail('Ville', customer.city)}{detail('Adresse', customer.address)}</dl></section>
            <section className="profile-section"><h3><span>03</span> Dernières activités</h3><ol className="activity-list">{activity.map((event, i) => <li key={i}><span className="activity-dot" /><div><strong>{event.action === 'created' ? 'Fichier créé' : event.action === 'document_added' ? 'Photo du document enregistrée' : 'Fichier modifié'}</strong><p>{event.actor} · {date(event.created_at)}</p></div></li>)}</ol></section>
            </div>
            <div role="tabpanel" id="section-documents" aria-labelledby="section-tab-documents" hidden={section !== 'documents'}>{documentCards()}</div>
          </div><div className="drawer-footer"><button className="secondary" onClick={close}>Fermer</button><button className="primary" onClick={edit}>Modifier le fichier <span aria-hidden="true">↗</span></button></div>
        </> : mode !== 'view' ? <form ref={formRef} onSubmit={submit} noValidate>
          <fieldset disabled={saving} className="customer-fieldset"><div className="drawer-body">
            <div role="tabpanel" id="section-information" aria-labelledby="section-tab-information" hidden={section !== 'information'}>
            <section className="profile-section"><h3><span>01</span> L’essentiel <small>Obligatoire</small></h3><div className="client-form-grid">{field('first_name', 'Prénom', 'text', true)}{field('last_name', 'Nom', 'text', true)}{field('phone', 'Téléphone principal', 'tel', true, 'Ex. 0612345678 ou +33612345678')}</div></section>
            <details className="optional-details" open={mode === 'edit' || Object.keys(errors).some(k => !['first_name', 'last_name', 'phone'].includes(k) && errors[k]?.length)}><summary><span>02</span><div>Informations complémentaires<small>Facultatif · maintenant ou plus tard</small></div><span className="details-plus" aria-hidden="true">＋</span></summary>
              <div className="client-form-grid">{field('secondary_phone', 'Téléphone secondaire', 'tel')}{field('email', 'E-mail', 'email')}{field('city', 'Ville')}
                <div className="client-field"><label htmlFor="client-language">Langue de communication<FieldHelp field={"language"} label={"Langue de communication"}/></label><select name="language" aria-invalid={!!errors.language?.length} aria-describedby={errors.language?.length?"error-language":undefined} aria-label="Langue de communication" id="client-language" value={form.language} onChange={e => setForm({ ...form, language: e.target.value })}><option value="fr">Français</option><option value="ar">Arabe</option></select>{errors.language?.[0]&&<small id="error-language" className="field-error">{errors.language[0]}</small>}</div>
                {field('address', 'Adresse')}
              </div>
            </details>
            <p className="form-privacy"><Lock /> Ces informations restent dans l’espace privé de votre agence.</p>
            </div>
            <div role="tabpanel" id="section-documents" aria-labelledby="section-tab-documents" hidden={section !== 'documents'}>{documentCards()}</div>
          </div><div className="drawer-footer"><button type="button" className="secondary" onClick={mode === 'edit' ? cancelEdit : close}>Annuler</button><button className="primary" type="submit">{saving ? saveProgress : mode === 'create' ? 'Créer le client' : 'Enregistrer les modifications'}<span aria-hidden="true">✓</span></button></div></fieldset>
        </form> : <div className="drawer-footer"><button className="secondary" onClick={close}>Fermer</button></div>}
      </>}
    </dialog>
  </section>;
}

function Search() { return <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 5 5" /></svg>; }
function Lock() { return <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" /></svg>; }
