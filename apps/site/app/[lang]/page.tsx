import Link from 'next/link';
import { notFound } from 'next/navigation';
export default async function Page({ params }: { params: Promise<{lang: string}> }) {
  const { lang } = await params;
  if (lang !== 'fr' && lang !== 'ar') notFound();
  const ar = lang === 'ar';
  return <main style={{maxWidth:760,margin:'60px auto',padding:32,lineHeight:1.8}}>
    <strong>{ar ? 'بن صبيح · كراء السيارات' : 'Ben Sbih · Location de voitures'}</strong>
    <h1>{ar ? 'الموقع قيد التطوير' : 'Le site est en préparation'}</h1>
    <p>{ar ? 'هذه بيئة تطوير محلية. عرض السيارات والحجز عبر الموقع لم يبدآ بعد.' : 'Environnement de développement local. Le catalogue et les réservations ne sont pas encore disponibles.'}</p>
    <Link href={ar ? '/fr' : '/ar'} lang={ar ? 'fr' : 'ar'}>{ar ? 'Français' : 'العربية'}</Link>
  </main>;
}
