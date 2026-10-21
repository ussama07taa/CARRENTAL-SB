import { redirect } from 'next/navigation';
import { readSession } from '../_lib/session';
import LoginForm from './login-form';

export const dynamic = 'force-dynamic';
export default async function LoginPage() {
  const result = await readSession().catch(() => null);
  if (result?.status === 200) redirect('/');
  return <main className="login-shell">
    <header className="brand"><span className="brand-mark" aria-hidden="true">B.</span><div><strong>BENSEBIHI</strong><span className="brand-caption">CAR RENTAL · ESPACE AGENCE</span></div></header>
    <section className="login-card">
      <p className="eyebrow">VOTRE ESPACE DE TRAVAIL</p>
      <h1>Content de vous revoir.</h1>
      <p className="intro">Connectez-vous à votre agence.</p>
      <LoginForm />
    </section>
    <footer>Accès réservé aux membres de l’agence.</footer>
  </main>;
}
