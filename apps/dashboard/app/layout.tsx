import type { Metadata } from 'next';
import FormFeedback from './_components/form-feedback';
import './theme-tokens.css';
import './globals.css';
import './design-system.css';
import './workspace.css';
import './workspace-refined.css';
import './theme.css';
import './workspace-polish.css';

export const metadata: Metadata = { title: 'Espace agence', robots: { index: false, follow: false } };
export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="fr"><body><FormFeedback/>{children}</body></html>;
}
