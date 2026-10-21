import type { Metadata } from 'next';
import { headers } from 'next/headers';
export const metadata: Metadata = { title: 'Ben Sbih', robots: { index: false, follow: false } };
export default async function Layout({children}: {children: React.ReactNode}) {
  const lang = (await headers()).get('x-site-language') === 'ar' ? 'ar' : 'fr';
  return <html lang={lang} dir={lang === 'ar' ? 'rtl' : 'ltr'}><body style={{margin:0,background:'#f5f6fa',color:'#242938',fontFamily:'Arial, sans-serif'}}>{children}</body></html>;
}
