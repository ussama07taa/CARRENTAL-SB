import {notFound} from 'next/navigation';
import Studio from './studio';
import './studio.css';
export default function Page(){if(process.env.NODE_ENV==='production')notFound();return <Studio/>}
