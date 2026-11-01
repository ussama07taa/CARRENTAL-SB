import { CarFront, Home, MessageCircle } from 'lucide-react';
import type { ContactLanguage } from '../../lib/whatsapp';
import './not-found-page.css';
const copy = {
 fr: {title:'Cette page est introuvable.',text:'Le lien est peut-être incorrect ou la page a été déplacée. Retrouvez nos voitures ou revenez à l’accueil.',home:'Retour à l’accueil',cars:'Découvrir notre flotte',help:'Besoin d’aide ? Contactez-nous'},
 en: {title:'We couldn’t find this page.',text:'The link may be incorrect or the page may have moved. Explore our cars or return to the home page.',home:'Back to home',cars:'Explore our fleet',help:'Need help? Contact us'},
 es: {title:'No encontramos esta página.',text:'El enlace puede ser incorrecto o la página puede haber cambiado. Descubre nuestros coches o vuelve al inicio.',home:'Volver al inicio',cars:'Ver nuestra flota',help:'¿Necesitas ayuda? Contáctanos'},
 ar: {title:'هاد الصفحة ما لقيناهاش.',text:'يمكن الرابط ما صحيحش أو الصفحة تبدلات. تقدر تشوف السيارات ديالنا أو ترجع للرئيسية.',home:'الرجوع للرئيسية',cars:'اكتشف أسطولنا',help:'محتاج مساعدة؟ تواصل معنا'}
};
export default function NotFoundPage({language}:{language:ContactLanguage}) {
 const t=copy[language];
 return <main className="site-not-found" dir={language==='ar'?'rtl':'ltr'} lang={language}>
  <div className="site-not-found-card"><span className="site-not-found-code">404</span>
   <h1>{t.title}</h1><p>{t.text}</p>
   <div className="site-not-found-actions"><a className="site-not-found-primary" href={`/${language}/voitures`}><CarFront size={20} aria-hidden="true"/>{t.cars}</a><a href={`/${language}`}><Home size={20} aria-hidden="true"/>{t.home}</a></div>
   <a className="site-not-found-help" href={`/${language}/contact`}><MessageCircle size={18} aria-hidden="true"/>{t.help}</a>
  </div>
 </main>;
}
