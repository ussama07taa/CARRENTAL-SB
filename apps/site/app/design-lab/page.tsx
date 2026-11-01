import {notFound} from 'next/navigation';
import Lab from './lab';
import './lab.css';
export default function Page(){if(process.env.NODE_ENV==='production')notFound();return <Lab/>}
