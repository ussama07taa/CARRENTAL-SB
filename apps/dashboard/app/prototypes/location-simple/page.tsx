import {moroccoDateTimeFormat} from '../../_lib/morocco-time';
import {redirect} from 'next/navigation';
import {readSession} from '../../_lib/session';
import RentalPrototype from './prototype';
import './prototype.css';

export const dynamic = 'force-dynamic';
export default async function Page() {
  const result = await readSession();
  if (!result.session) redirect('/login');
  const parts = moroccoDateTimeFormat('en-CA', {timeZone:'Africa/Casablanca', year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
  const part = (type:string)=>parts.find(p=>p.type===type)!.value;
  return <RentalPrototype today={`${part('year')}-${part('month')}-${part('day')}`}/>;
}
