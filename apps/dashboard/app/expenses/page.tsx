import FinancialAccess from '../_components/financial-access';
import {redirect} from 'next/navigation';
import {readSession} from '../_lib/session';
import Workspace from '../workspace';
import Expenses from './expenses';
import '../vehicles/vehicles.css';
import './expenses.css';
import '../loans/loans.css';
export const dynamic='force-dynamic';
export default async function Page(){const result=await readSession();if(result.status===401)redirect('/login');return <Workspace session={result.session}>{result.session?.can_manage_expenses?<Expenses/>:<FinancialAccess/>}</Workspace>;}
