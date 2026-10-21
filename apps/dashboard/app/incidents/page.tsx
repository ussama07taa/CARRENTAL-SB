import {redirect} from 'next/navigation';
import {readSession} from '../_lib/session';
import Workspace from '../workspace';
import Tickets from './tickets';
import './tickets.css';
export const dynamic='force-dynamic';
export default async function Page(){const result=await readSession();if(result.status===401)redirect('/login');return <Workspace session={result.session}><Tickets/></Workspace>;}
