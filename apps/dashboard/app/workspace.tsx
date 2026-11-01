'use client';
import AgencyBrand from './_components/agency-brand';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { usePathname } from 'next/navigation';
import { Settings, Archive, CarFront, ChartNoAxesCombined, ChevronRight, ClipboardList, Landmark, LayoutDashboard, LogOut, Menu, ReceiptText, ShieldCheck, Tags, UsersRound, Wallet, Wrench, X } from 'lucide-react';
import type { Session } from './_lib/session';
import { api, authPost } from './_lib/auth-client';
import NotificationCenter from './_components/notification-center';
import QuickSearch from './_components/quick-search';

export default function Workspace({ session, children }: { session: Session | null; children?: ReactNode }) {
  const pathname = usePathname();
  const [websiteCount,setWebsiteCount]=useState<number|null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [menuOpen, setMenuOpen] = useState(false);
  const [navCompact, setNavCompact] = useState(true);
  useEffect(() => { try { setNavCompact(localStorage.getItem('bensebihi.navCompact') === 'true'); } catch {} }, []);
  function toggleNav() { setNavCompact(value => { const next = !value; try { localStorage.setItem('bensebihi.navCompact', String(next)); } catch {} return next; }); }
  const menuButton = useRef<HTMLButtonElement>(null);
  const root = useRef<HTMLDivElement>(null);
  const navigation = [
    { href: '/', label: 'Accueil', icon: LayoutDashboard },
    { href: '/clients', label: 'Clients', icon: UsersRound },
    { href: '/vehicles', label: 'Véhicules', icon: CarFront },
    { href: '/reservations', label: 'Réservations', icon: ClipboardList },
    { href: '/pricing', label: session?.can_manage_expenses ? 'Tarifs et saisons' : 'Consulter les tarifs', icon: Tags },
    { href: '/maintenance', label: 'Entretien & suivi', icon: Wrench }, { href: '/fines', label: 'Infractions', icon: ShieldCheck },
    ...(session?.can_manage_expenses ? [{ href: '/expenses', label: 'Dépenses', icon: ReceiptText }] : []),
    ...(session?.can_manage_expenses ? [{ href: '/cash', label: 'Caisse', icon: Wallet }] : []),
    ...(session?.can_manage_expenses ? [{ href: '/loans', label: 'Achats & crédits', icon: Landmark }] : []),
    ...(session?.can_manage_expenses ? [{ href: '/reports', label: 'Rapports', icon: ChartNoAxesCombined }] : []),
    ...(session?.can_manage_expenses ? [{ href: '/users', label: 'Utilisateurs', icon: UsersRound }] : []),
    ...(session?.can_manage_expenses ? [{ href: '/settings/agency', label: 'Paramètres', icon: Settings }, { href: '/settings/backups', label: 'Sauvegardes', icon: Archive }] : []),
  ];
  useEffect(() => { setMenuOpen(false); }, [pathname]);
  useEffect(() => {
    if (!menuOpen) return;
    const close = (event: KeyboardEvent) => { if (event.key === 'Escape') { setMenuOpen(false); menuButton.current?.focus(); } };
    window.addEventListener('keydown', close);
    return () => window.removeEventListener('keydown', close);
  }, [menuOpen]);
  useEffect(() => {
    const hide = () => { if (root.current) root.current.style.visibility = 'hidden'; };
    const restore = (event: PageTransitionEvent) => { if (event.persisted) window.location.reload(); };
    const verify = async () => {
      if (document.visibilityState !== 'visible') return;
      try {
        const response = await fetch(`${api}/api/v1/auth/me`, { credentials: 'include', headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(5000) });
        if (response.status === 401 || response.status === 403) { hide(); window.location.replace('/login'); }
        else if (response.ok) { const current = await response.json() as Session; if (current.user.id !== session?.user.id || current.can_manage_expenses !== session?.can_manage_expenses) { hide(); window.location.reload(); } }
      } catch { /* Logout still reports network failure explicitly. */ }
    };
    window.addEventListener('pagehide', hide);
    window.addEventListener('pageshow', restore);
    document.addEventListener('visibilitychange', verify);
    return () => { window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', restore); document.removeEventListener('visibilitychange', verify); };
  }, []);
  async function logout() {
    if (busy) return;
    setBusy(true); setError('');
    try {
      const response = await authPost('logout');
      if (!response.ok) { setError('Déconnexion non confirmée. Réessayez.'); return; }
      if (root.current) root.current.style.visibility = 'hidden';
      window.location.replace('/login');
    } catch { setError('Le serveur est injoignable. La déconnexion n’est pas confirmée ; réessayez.'); }
    finally { setBusy(false); }
  }
  return <div className={`workspace app-shell${navCompact ? ' app-nav-compact' : ''}${!session ? ' app-shell-unavailable' : ''}`} ref={root}>
    <a className="app-skip" href="#app-content">Aller au contenu</a>
    <header className="workspace-header"><div className="brand"><AgencyBrand/></div><div className="app-header-actions">{session && <><button ref={menuButton} className="app-menu-toggle" aria-controls="app-navigation" aria-expanded={menuOpen} onClick={() => setMenuOpen(open => !open)}>{menuOpen ? <X size={18} aria-hidden="true"/> : <Menu size={18} aria-hidden="true"/>}<span>Menu</span></button><QuickSearch/><NotificationCenter onWebsiteCount={setWebsiteCount}/><div className="app-header-profile"><span className="app-profile-avatar" aria-hidden="true">{session.user.name.trim().slice(0,1).toUpperCase()}</span><div><strong>{session.user.name}</strong><small>{session.can_manage_expenses ? 'Espace responsable' : 'Espace employé'}</small></div></div></>}{!session&&<button className="secondary app-logout" onClick={logout} disabled={busy}><LogOut size={16} aria-hidden="true"/><span>{busy ? 'Déconnexion…' : 'Se déconnecter'}</span></button>}</div></header>
    {error && <p role="alert" className="notice error">{error}</p>}
    {session ? <>
      {session.demo && <p className="demo-banner">MODE DÉMONSTRATION <span>Données fictives · aucun compte réel</span></p>}
      <aside id="app-navigation" className="app-sidebar" data-open={menuOpen}><button type="button" className="app-nav-size" onClick={toggleNav} aria-label={navCompact ? "Agrandir le menu" : "Réduire le menu"} aria-expanded={!navCompact} aria-controls="agency-nav" title={navCompact ? "Agrandir le menu" : "Réduire le menu"}><Menu size={19}/><span>Réduire le menu</span><ChevronRight size={16}/></button><div className="app-nav-label">VOTRE AGENCE</div><nav id="agency-nav" className="app-nav" aria-label="Navigation principale">{navigation.map(({href,label,icon:Icon}) => <a key={href} href={href==='/reservations'&&websiteCount?'/reservations?source=website&status=demande':href} title={href==='/reservations'&&websiteCount?`${label} · ${websiteCount} demandes du site à traiter`:label} aria-label={href==='/reservations'&&websiteCount?`${label} · ${websiteCount} demandes du site à traiter`:label} aria-current={pathname === href || (href !== '/' && pathname.startsWith(href + '/')) ? 'page' : undefined}><Icon size={19} strokeWidth={1.7} aria-hidden="true"/><span>{label}</span>{href==='/reservations'&&!!websiteCount&&<b className="app-request-badge" aria-hidden="true">{websiteCount>99?'99+':websiteCount}</b>}<ChevronRight className="app-nav-chevron" size={14} aria-hidden="true"/></a>)}</nav><button className="app-nav-logout" onClick={logout} disabled={busy} aria-label="Se déconnecter" title="Se déconnecter"><LogOut size={20} aria-hidden="true"/><span>{busy ? 'Déconnexion…' : 'Se déconnecter'}</span></button><div className="app-profile"><span className="app-profile-avatar" aria-hidden="true">{session.user.name.trim().slice(0,1).toUpperCase()}</span><div><strong>{session.user.name}</strong><span><ShieldCheck size={12} aria-hidden="true"/> {session.can_manage_expenses ? 'Responsable' : 'Employé'}</span></div></div></aside>
      <main id="app-content" className="app-content" tabIndex={-1}>{children ?? <section className="welcome"><span className="status"><span aria-hidden="true">●</span> Session ouverte</span><h1>Bonjour, {session.user.name}.</h1><p>Vous êtes connecté à votre espace agence.</p>
        <dl className="identity-grid"><div><dt>Votre agence</dt><dd>{session.company.name}</dd></div><div><dt>Responsable de l’agence</dt><dd>{session.responsible.name}</dd></div></dl>
        <a href="/clients" className="primary">Ouvrir les fichiers clients <span aria-hidden="true">↗</span></a>
      </section>}</main>
    </> : <main id="app-content" className="app-content" tabIndex={-1}><section className="welcome"><h1>Accès à l’agence indisponible.</h1><p>Votre compte n’a pas de rattachement valide. Contactez le responsable, ou déconnectez-vous.</p></section></main>}
    <footer>Espace privé de votre agence</footer>
  </div>;
}
