import { notFound } from 'next/navigation';
export default async function Layout({ children, params }: { children: React.ReactNode; params: Promise<{lang: string}> }) {
  const { lang } = await params;
  if (lang !== 'fr' && lang !== 'ar') notFound();
  return <>{children}</>;
}
