'use client';
export default function ErrorPage({ reset }: { reset: () => void }) {
  return <main className="login-shell"><section className="login-card"><h1>Connexion interrompue.</h1><p>Le serveur est momentanément indisponible. Vos informations ne sont pas affichées.</p><button className="primary" onClick={reset}>Réessayer</button></section></main>;
}
