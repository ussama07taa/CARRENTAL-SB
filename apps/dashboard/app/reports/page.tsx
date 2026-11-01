import FinancialAccess from '../_components/financial-access';
import {redirect} from 'next/navigation';
import {readSession} from '../_lib/session';
import Workspace from '../workspace';
import Reports from './reports';
import '../vehicles/vehicles.css';
import '../loans/loans.css';
import './reports.css';
export const dynamic='force-dynamic';
export default async function Page({searchParams}:{searchParams:Promise<{month?:string;vehicle_id?:string}>}){const query=await searchParams;const result=await readSession();if(result.status===401)redirect('/login');return <Workspace session={result.session}>{result.session?.can_manage_expenses?<Reports initialMonth={query.month&&/^\d{4}-\d{2}$/.test(query.month)?query.month:undefined} initialVehicle={query.vehicle_id&&/^\d+$/.test(query.vehicle_id)?Number(query.vehicle_id):undefined}/>:<FinancialAccess/>}</Workspace>;}
