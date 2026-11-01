import { headers } from 'next/headers';
import NotFoundPage from '../components/public-site/not-found-page';
import type { ContactLanguage } from '../lib/whatsapp';
export default async function NotFound() {
 const value=(await headers()).get('x-site-language');
 const language:ContactLanguage=value==='ar'||value==='en'||value==='es'?value:'fr';
 return <NotFoundPage language={language}/>;
}
