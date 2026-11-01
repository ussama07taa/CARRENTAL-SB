import { pageMetadata } from '../../lib/seo';
import PublicSite from '../../components/public-site/preview';
export default async function Page({params}:{params:Promise<{lang:'fr'|'ar'|'en'|'es'}>}){const {lang}=await params;return <PublicSite initialLanguage={lang}/>;}

export async function generateMetadata({params}:{params:Promise<{lang:string}>}) {return pageMetadata((await params).lang,'');}
