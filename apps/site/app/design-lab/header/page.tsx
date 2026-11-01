import {notFound} from 'next/navigation';
import HeaderPreview from './preview';
export default function Page(){if(process.env.NODE_ENV==='production')notFound();return <HeaderPreview/>}
