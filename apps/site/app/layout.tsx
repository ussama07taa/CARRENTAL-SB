import type { Metadata } from 'next';
import { headers } from 'next/headers';
import '@fontsource/tajawal/400.css';
import '@fontsource/tajawal/500.css';
import '@fontsource/tajawal/700.css';
import '@fontsource/tajawal/800.css';
import './arabic-font.css';
import {CurrencyProvider} from '../components/website/display-currency';
export const metadata: Metadata = { title: 'BEN SBIH · Rental Car', icons: { icon: '/branding/ben-sbih-car-mark.svg' }, robots: { index: false, follow: false } };
export default async function Layout({children}: {children: React.ReactNode}) {
  const requestHeaders=await headers();
  const lang = requestHeaders.get('x-site-language') ?? 'fr';
  // Only consume the platform country header on its trusted hosting environment.
  const country=process.env.VERCEL==='1'?requestHeaders.get('x-vercel-ip-country')??undefined:undefined;
  return <html lang={lang} dir={lang === 'ar' ? 'rtl' : 'ltr'}><body style={{margin:0,background:'#ffffff',color:'#071c47',fontFamily:'Arial, sans-serif'}}><CurrencyProvider country={country}>{children}</CurrencyProvider></body></html>;
}
