import { redirect } from 'next/navigation';
import { readSession } from './_lib/session';
import Workspace from './workspace';
import Cockpit from './_components/cockpit';
import './vehicles/vehicles.css';
import './loans/loans.css';
import './cockpit.css';
import './cockpit-clarity.css';

export const dynamic = 'force-dynamic';
export default async function Page() {
  const result = await readSession();
  if (result.status === 401) redirect('/login');
  return <Workspace session={result.session}>{result.session && <Cockpit session={result.session}/>}</Workspace>;
}
