import {redirect} from 'next/navigation';
import {readSession} from '../../_lib/session';
import Workspace from '../../workspace';
import AgencyProfile from './profile';
import './profile.css';
export const dynamic='force-dynamic';
export default async function Page(){const result=await readSession();if(result.status===401)redirect('/login');return <Workspace session={result.session}>{result.session?.can_manage_expenses?<AgencyProfile/>:<p>Les paramètres de l’agence sont réservés au gérant.</p>}</Workspace>;}
