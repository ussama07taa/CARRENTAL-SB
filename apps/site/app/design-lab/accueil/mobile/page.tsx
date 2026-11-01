import {notFound} from 'next/navigation';
export default async function Page({searchParams}:{searchParams:Promise<{width?:string}>}){
 if(process.env.NODE_ENV==='production')notFound();
 const {width:requested}=await searchParams;
 const width=[320,390,430,768].includes(Number(requested))?Number(requested):390;
 return <main style={{minHeight:'100vh',background:'#eef1f6',padding:'16px 12px 24px',display:'flex',flexDirection:'column',alignItems:'center',gap:16}}>
  <nav aria-label="Largeur de la prévisualisation" style={{display:'flex',gap:8,fontFamily:'Arial, sans-serif'}}>{[320,390,430,768].map(size=><a key={size} href={'?width='+size} aria-current={width===size?'page':undefined} style={{padding:'10px 12px',borderRadius:6,fontSize:12,textDecoration:'none',background:width===size?'#071c47':'white',color:width===size?'white':'#071c47'}}>{size} px</a>)}</nav>
  <iframe title={'Accueil · aperçu '+width+' px'} src="/design-lab/accueil" style={{width,maxWidth:'100%',height:width<600?1200:950,border:0,borderRadius:12,background:'white',boxShadow:'0 0 0 1px #d9e0ec'}}/>
 </main>
}
