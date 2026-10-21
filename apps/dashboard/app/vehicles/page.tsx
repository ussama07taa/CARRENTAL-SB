import { redirect } from 'next/navigation';
import { readSession } from '../_lib/session';
import Workspace from '../workspace';
import Vehicles from './vehicles';
import './vehicles.css';
export const dynamic = 'force-dynamic';
export default async function Page() {
  const result = await readSession();
  if (result.status === 401) redirect('/login');
  return <Workspace session={result.session}><Vehicles /></Workspace>;
}
