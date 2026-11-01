import { pageMetadata, publicPaths, publishedVehicles } from '../../../lib/seo';
import { notFound } from 'next/navigation';
import AboutPage from '../../../components/public-site/about-page';
import LegalPage from '../../../components/public-site/legal-page';
import FaqPage from '../../../components/public-site/faq-page';
import ContactPage from '../../../components/public-site/contact-page';
import Website from '../../../components/website/website';
import PublicSite from '../../../components/public-site/preview';
export default async function Page({params}:{params:Promise<{lang:'fr'|'ar'|'en'|'es';path:string[]}>}){const {lang,path}=await params;if(path.length===1&&path[0]==='a-propos')return <AboutPage language={lang}/>;if(path.length===1&&(path[0]==='conditions'||path[0]==='confidentialite'))return <LegalPage language={lang} kind={path[0]==='conditions'?'terms':'privacy'}/>;if(path.length===1&&path[0]==='faq')return <FaqPage language={lang}/>;if(path.length===1&&path[0]==='contact')return <ContactPage language={lang}/>;if(path[0]==='voitures'&&path.length===2&&/^\d+$/.test(path[1]))return <PublicSite initialLanguage={lang} initialVehicle={path[1]}/>;if(path.length===1&&path[0]==='voitures')return <Website/>;return notFound();}

export async function generateMetadata({params}:{params:Promise<{lang:string;path:string[]}>}) {
 const {lang,path}=await params;
 if(path.length===1 && publicPaths.includes(path[0] as typeof publicPaths[number])) return pageMetadata(lang,path[0] as typeof publicPaths[number]);
 if(path.length===2&&path[0]==='voitures'&&/^\d+$/.test(path[1])) {
  const car=(await publishedVehicles()).find(v=>String(v.id)===path[1]);
  if(car)return pageMetadata(lang,'vehicle',`voitures/${car.id}`,[car.make,car.model,car.year].filter(Boolean).join(' '));
  return pageMetadata(lang,'voitures');
 }
 return {};
}
