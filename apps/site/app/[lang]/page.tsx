import PublicSite from '../../components/public-site/preview';
export default async function Page({params}:{params:Promise<{lang:'fr'|'ar'|'en'|'es'}>}){const {lang}=await params;return <PublicSite initialLanguage={lang}/>;}
