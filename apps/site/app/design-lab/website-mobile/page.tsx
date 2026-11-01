import {notFound} from 'next/navigation';
export default function Page(){if(process.env.NODE_ENV==='production')notFound();return <main style={{background:'#e5eaf2',minHeight:'100vh',padding:20,display:'flex',justifyContent:'center'}}><iframe src="/ar" title="Website mobile preview" style={{border:0,width:390,height:844,maxWidth:'100%',background:'white',borderRadius:18}}/></main>}
