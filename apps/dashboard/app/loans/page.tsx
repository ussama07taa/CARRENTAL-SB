import {redirect} from 'next/navigation';
import {readSession} from '../_lib/session';
import Workspace from '../workspace';
import Loans from './loans';
import '../vehicles/vehicles.css';
import './loans.css';
export const dynamic='force-dynamic';
export default async function Page({searchParams}:{searchParams:Promise<{vehicle?:string}>}){const session=await readSession();if(session.status===401)redirect('/login');const params=await searchParams;const vehicle=/^[1-9][0-9]*$/.test(params.vehicle??'')?Number(params.vehicle):undefined;return <Workspace session={session.session}><Loans vehicleId={vehicle}/></Workspace>;}
