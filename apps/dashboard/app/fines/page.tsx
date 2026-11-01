import {redirect} from 'next/navigation';
import {readSession} from '../_lib/session';
import Workspace from '../workspace';
import Lookup from './fine-files';
export const dynamic='force-dynamic';
export default async function Page(){const r=await readSession();if(r.status===401)redirect('/login');return <Workspace session={r.session}><Lookup/></Workspace>;}