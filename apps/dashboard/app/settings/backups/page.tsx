import {redirect} from 'next/navigation';
import {readSession} from '../../_lib/session';
import Workspace from '../../workspace';
import Backups from '../backups';
import '../settings.css';
export const dynamic='force-dynamic';
export default async function Page(){const result=await readSession();if(result.status===401)redirect('/login');return <Workspace session={result.session}>{result.session?.can_manage_expenses?<Backups/>:<p>Les paramètres sont réservés au gérant.</p>}</Workspace>;}
