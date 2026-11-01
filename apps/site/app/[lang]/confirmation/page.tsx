import { pageMetadata } from '../../../lib/seo';
import {Confirmation} from '../../../components/booking/booking';
export default function Page(){return <Confirmation/>;}

export async function generateMetadata({params}:{params:Promise<{lang:string}>}) {return {...pageMetadata((await params).lang,'confirmation'),referrer:'no-referrer' as const};}
