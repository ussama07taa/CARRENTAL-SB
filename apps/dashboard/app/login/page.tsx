import { redirect } from 'next/navigation';
import AgencyBrand from '../_components/agency-brand';
import { readSession } from '../_lib/session';
import LoginForm from './login-form';

export const dynamic = 'force-dynamic';
export default async function LoginPage() {
  const result = await readSession().catch(() => null);
  if (result?.status === 200) redirect('/');
  const installation = await fetch(`${process.env.API_INTERNAL_URL ?? 'http://127.0.0.1:8000'}/api/v1/installation`, {cache:'no-store',signal:AbortSignal.timeout(5000)}).then(r=>r.ok?r.json():null).catch(()=>null);
  if(installation?.available) redirect('/setup');
  return <main className="login-shell">
    <header className="brand"><AgencyBrand/></header>
    <section className="login-card">
      <p className="eyebrow">VOTRE ESPACE DE TRAVAIL</p>
      <h1>Content de vous revoir.</h1>
      <p className="intro">Connectez-vous à votre agence.</p>
      <LoginForm />
    </section>
    <footer>Accès réservé aux membres de l’agence.</footer>
  </main>;
}
