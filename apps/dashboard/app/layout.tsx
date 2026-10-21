import type { Metadata } from 'next';
import './globals.css';
import './design-system.css';
import './workspace.css';
import './workspace-refined.css';

export const metadata: Metadata = { title: 'Ben Sbih · Développement', robots: { index: false, follow: false } };
export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="fr"><body>{children}</body></html>;
}
