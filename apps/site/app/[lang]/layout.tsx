import BackToTop from '../../components/public-site/back-to-top';
import GlobalHeader from '../../components/public-site/global-header';
import SiteFooter from '../../components/public-site/site-footer';
import MobileNavigation from '../../components/public-site/mobile-navigation';
import { notFound } from 'next/navigation';
export default async function Layout({ children, params }: { children: React.ReactNode; params: Promise<{lang: string}> }) {
  const { lang } = await params;
  if (!['fr','ar','en','es'].includes(lang)) notFound();
  return <div className="public-site-shell"><GlobalHeader language={lang as 'fr'|'ar'|'en'|'es'}/>{children}<SiteFooter language={lang as 'fr'|'ar'|'en'|'es'}/><BackToTop language={lang as 'fr'|'ar'|'en'|'es'}/><MobileNavigation language={lang as 'fr'|'ar'|'en'|'es'}/></div>;
}
