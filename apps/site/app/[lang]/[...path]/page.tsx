import AboutPage from '../../../components/public-site/about-page';
import LegalPage from '../../../components/public-site/legal-page';
import FaqPage from '../../../components/public-site/faq-page';
import ContactPage from '../../../components/public-site/contact-page';
import Website from '../../../components/website/website';
import PublicSite from '../../../components/public-site/preview';
export default async function Page({params}:{params:Promise<{lang:'fr'|'ar'|'en'|'es';path:string[]}>}){const {lang,path}=await params;if(path.length===1&&path[0]==='a-propos')return <AboutPage language={lang}/>;if(path.length===1&&(path[0]==='conditions'||path[0]==='confidentialite'))return <LegalPage language={lang} kind={path[0]==='conditions'?'terms':'privacy'}/>;if(path.length===1&&path[0]==='faq')return <FaqPage language={lang}/>;if(path.length===1&&path[0]==='contact')return <ContactPage language={lang}/>;if(path[0]==='voitures'&&path.length===2&&/^\d+$/.test(path[1]))return <PublicSite initialLanguage={lang} initialVehicle={path[1]}/>;return <Website/>;}
