import { pageMetadata } from '../../../lib/seo';
import {Tracking} from '../../../components/booking/booking';

export default function Page(){return <Tracking/>;}

export async function generateMetadata({params}:{params:Promise<{lang:string}>}) {return {...pageMetadata((await params).lang,'suivi'),referrer:'no-referrer' as const};}
