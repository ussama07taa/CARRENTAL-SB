import { NextRequest } from 'next/server';
const allowed = /^(vehicles(?:\/[0-9]+(?:\/calendar)?)?|requests(?:\/lookup)?|photo\/[0-9]+(?:\/(?:cover|front|rear|interior|boot|detail_[123]))?)$/;
async function proxy(req: NextRequest, context: {params:Promise<{path:string[]}>}) {
 const path=(await context.params).path.join('/');
 if(!allowed.test(path)||(req.method==='POST'&&!['requests','requests/lookup'].includes(path))||(req.method==='GET'&&path==='requests/lookup')) return new Response(null,{status:404});
 // Next's internal URL may use the listen address (0.0.0.0), not the browser's host.
 if(req.method==='POST') {
  let sameOrigin=false;
  try {const origin=new URL(req.headers.get('origin')??'');sameOrigin=origin.host===req.headers.get('host')&&origin.protocol===req.nextUrl.protocol;}catch{}
  if(!sameOrigin)return Response.json({message:'ORIGIN_REJECTED'},{status:403});
 }
 try {
  const data=req.method==='POST'?await req.text():undefined;
  if(data && data.length>12000) return new Response(null,{status:413});
  const upstream=await fetch((process.env.SITE_API_URL??'http://127.0.0.1:8000')+'/api/v1/public/'+path+req.nextUrl.search,{method:req.method,headers:{Accept:'application/json',...(data?{'Content-Type':'application/json'}:{})},body:data,cache:'no-store',signal:AbortSignal.timeout(15000)});
  return new Response(upstream.body,{status:upstream.status,headers:{'Content-Type':upstream.headers.get('content-type')??'application/json','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'}});
 } catch {return Response.json({message:'SERVICE_UNAVAILABLE'},{status:503});}
}
export {proxy as GET,proxy as POST};
