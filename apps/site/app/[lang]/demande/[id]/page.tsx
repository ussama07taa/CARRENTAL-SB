import { pageMetadata } from '../../../../lib/seo';
import Booking from '../../../../components/booking/booking';
export default function Page(){return <Booking/>;}

export async function generateMetadata({params}:{params:Promise<{lang:string}>}) {return {...pageMetadata((await params).lang,'demande'),referrer:'no-referrer' as const};}
