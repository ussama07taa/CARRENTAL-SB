'use client';
import {useEffect,useState} from 'react';
import {api} from '../_lib/auth-client';
import './followups.css';
export default function DamageFollowups(){
 const [rows,setRows]=useState<{id:number;title:string;next_action:string;follow_up_on:string}[]>([]),[count,setCount]=useState(0);
 useEffect(()=>{const abort=new AbortController();fetch(api+'/api/v1/damages?open=1',{credentials:'include',headers:{Accept:'application/json'},signal:abort.signal}).then(async r=>{if(!r.ok)return;const b=await r.json();setRows(b.data.slice(0,3));setCount(b.total);}).catch(()=>{});return()=>abort.abort();},[]);
 if(!count)return null;
 return <div className="damage-home"><section className="damage-home-card" aria-label="Dommages à suivre"><header><div><small>PARC · DOMMAGES</small><h2>Dommages à suivre <span>{count}</span></h2><p>Les réparations et démarches qui restent, même après la remise en service.</p></div><a href="/incidents">Tout le suivi ↗</a></header><ul>{rows.map(row=><li key={row.id}><div><small>DOM-{row.id}</small><strong>{row.title}</strong></div><div><span>{row.next_action}</span><small>À revoir le {new Intl.DateTimeFormat('fr-FR',{day:'numeric',month:'short',year:'numeric'}).format(new Date(row.follow_up_on+'T12:00:00'))}</small></div><a href={'/incidents?ticket='+row.id} aria-label={'Ouvrir le suivi DOM-'+row.id}>Ouvrir le suivi ↗</a></li>)}</ul></section></div>;
}
