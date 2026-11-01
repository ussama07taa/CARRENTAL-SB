import {redirect} from 'next/navigation';
import {readSession} from '../_lib/session';
import Workspace from '../workspace';
import Users from './users';
export const dynamic='force-dynamic';
export default async function Page(){const {status,session}=await readSession();if(status===401)redirect('/login');return <Workspace session={session}>{session?.can_manage_expenses?<Users/>:<section className="welcome"><h1>Accès réservé au responsable</h1><p>Seul le responsable peut ajouter ou désactiver un employé.</p><a href="/">Retour à mon accueil →</a></section>}</Workspace>;}
