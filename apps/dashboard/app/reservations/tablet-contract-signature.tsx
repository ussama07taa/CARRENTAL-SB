'use client';
import {useEffect,useRef,useState} from 'react';
import {api,apiMutation} from '../_lib/auth-client';
import DocumentViewer from '../vehicles/document-viewer';
import './tablet-contract-signature.css';

export function SignaturePad({label,disabled,onChange}:{label:string;disabled:boolean;onChange:(value:string)=>void}){
 const canvas=useRef<HTMLCanvasElement>(null),pointer=useRef<number|null>(null),previous=useRef<{x:number;y:number}|null>(null),distance=useRef(0);
 const [ink,setInk]=useState(false);
 function point(e:React.PointerEvent<HTMLCanvasElement>){const rect=e.currentTarget.getBoundingClientRect();return{x:(e.clientX-rect.left)*1000/rect.width,y:(e.clientY-rect.top)*300/rect.height};}
 function end(e:React.PointerEvent<HTMLCanvasElement>){if(pointer.current!==e.pointerId)return;pointer.current=null;previous.current=null;if(distance.current>=25){const source=e.currentTarget,ctx=source.getContext('2d');if(!ctx)return;const pixels=ctx.getImageData(0,0,1000,300).data;let left=1000,right=0,top=300,bottom=0;for(let y=0;y<300;y++)for(let x=0;x<1000;x++)if(pixels[(y*1000+x)*4+3]>16){left=Math.min(left,x);right=Math.max(right,x);top=Math.min(top,y);bottom=Math.max(bottom,y);}if(right<left)return;const output=document.createElement('canvas');output.width=1000;output.height=300;const target=output.getContext('2d');if(!target)return;const width=right-left+1,height=bottom-top+1,scale=Math.min(930/width,250/height);target.drawImage(source,left,top,width,height,(1000-width*scale)/2,(300-height*scale)/2,width*scale,height*scale);setInk(true);onChange(output.toDataURL('image/png'));}}
 return <div className="ts-pad"><div><strong>{label}</strong><button type="button" disabled={disabled} onClick={()=>{canvas.current?.getContext('2d')?.clearRect(0,0,1000,300);distance.current=0;setInk(false);onChange('');}}>Effacer {label.toLowerCase()}</button></div>
 <canvas ref={canvas} width={1000} height={300} aria-label={label} aria-disabled={disabled} onPointerDown={e=>{if(disabled||pointer.current!==null||e.button!==0)return;e.preventDefault();pointer.current=e.pointerId;previous.current=point(e);e.currentTarget.setPointerCapture(e.pointerId);}}
 onPointerMove={e=>{if(disabled||pointer.current!==e.pointerId||!previous.current)return;e.preventDefault();const next=point(e),ctx=e.currentTarget.getContext('2d');if(!ctx)return;ctx.strokeStyle='#173b53';ctx.lineWidth=6;ctx.lineCap='round';ctx.lineJoin='round';ctx.beginPath();ctx.moveTo(previous.current.x,previous.current.y);ctx.lineTo(next.x,next.y);ctx.stroke();distance.current+=Math.hypot(next.x-previous.current.x,next.y-previous.current.y);previous.current=next;}}
 onPointerUp={end} onPointerCancel={e=>{end(e);}} onLostPointerCapture={end}/><small>{ink?'Signature dessinée · non enregistrée':'Signez ici avec le doigt, un stylet ou la souris.'}</small></div>;
}

export default function TabletContractSignature({reservation,version,original,signedId,onDone,onCancel,onBusyChange}:{reservation:number;version:{id:number;version:number;reference:string;layout?:number|null};original:{id:number;sha256:string};signedId:number|null;onDone:()=>void;onCancel:()=>void;onBusyChange:(value:boolean)=>void}){
 const [client,setClient]=useState(''),[agency,setAgency]=useState(''),[read,setRead]=useState(false),[opened,setOpened]=useState(false),[showOriginal,setShowOriginal]=useState(false),[checked,setChecked]=useState(false),[consent,setConsent]=useState(false),[error,setError]=useState(''),[busy,setBusy]=useState(false),[uncertain,setUncertain]=useState(false),[preview,setPreview]=useState<{token:string;hash:string;url:string}|null>(null);
 const lock=useRef(false),key=useRef(''),body=useRef<Record<string,unknown>|null>(null),url=useRef('');
 const base=`reservations/${reservation}/departure/contract/${version.id}/tablet`;
 useEffect(()=>()=>{if(url.current)URL.revokeObjectURL(url.current);},[]);
 function clearPreview(){if(url.current)URL.revokeObjectURL(url.current);url.current='';setPreview(null);setChecked(false);setConsent(false);key.current='';body.current=null;}
 function updateClient(value:string){clearPreview();setClient(value);}function updateAgency(value:string){clearPreview();setAgency(value);}
 async function generate(){if(lock.current)return;lock.current=true;setBusy(true);onBusyChange(true);setError('');clearPreview();try{
  const form=new FormData();Object.entries({client_signature:client,agency_signature:agency,expected_signed_id:signedId??'',original_id:original.id,original_sha256:original.sha256,contract_read:'1'}).forEach(([k,v])=>form.append(k,String(v)));
  const response=await apiMutation(`${base}/preview`,'POST',form),data=await response.json();if(!response.ok)throw Error(data.message||'Prévisualisation impossible.');
  const pdf=await fetch(`${api}/api/v1/${base}/preview/${data.preview_token}`,{credentials:'include',cache:'no-store',signal:AbortSignal.timeout(15000)});if(!pdf.ok)throw Error('La prévisualisation est indisponible. Recommencez.');
  const blob=await pdf.blob();url.current=URL.createObjectURL(blob);setPreview({token:data.preview_token,hash:data.sha256,url:url.current});
 }catch(e){setError((e as Error).message);}finally{lock.current=false;setBusy(false);onBusyChange(false);}}
 async function confirm(){if(lock.current||!preview)return;lock.current=true;setBusy(true);onBusyChange(true);setError('');let unknown=false;try{
  if(!key.current)key.current=crypto.randomUUID();if(!body.current)body.current={preview_token:preview.token,preview_sha256:preview.hash,preview_checked:true,both_consented:true,reason:'Signatures dessinées de test après lecture et revue du PDF par les deux parties.',request_key:key.current};
  const response=await apiMutation(`${base}/confirm`,'POST',body.current);if(response.status>=500)throw new TypeError('Réponse incertaine.');const data=await response.json();if(!response.ok){key.current='';body.current=null;setUncertain(false);throw Error(data.message||'Enregistrement impossible.');}setUncertain(false);onDone();
 }catch(e){unknown=e instanceof TypeError||(e instanceof DOMException&&['TimeoutError','AbortError'].includes(e.name));setUncertain(unknown);setError(unknown?'Réponse incertaine. Vérifiez la même opération avant de continuer.':(e as Error).message);}finally{lock.current=false;setBusy(false);onBusyChange(unknown);}}
 const blocked=busy||uncertain;
 return <section className="ts" aria-label="Signature tablette de test"><header><h4>Signer sur tablette · {version.reference}</h4><span>ESPACE TEST</span></header>
 <details className="ts-help"><summary>ⓘ Comprendre la signature tablette</summary><p lang="ar" dir="rtl">هاد التوقيع مرسوم بالصبع أو قلم الشاشة أو الماوس، غير للتجربة وماشي توقيع إلكتروني مصادق عليه. قراو العقد بجوج، وقّعو، وشوفو PDF قبل تأكيد الحفظ. الأصل كيبقى محفوظ، والتوقيع بوحدو ما كيسجلش خروج السيارة. الكاشي الحالي غير مثال باسم الوكالة ومكتوب عليه TEST، حتى نزيدو الصورة الحقيقية.</p></details>
 <p>Doigt, stylet ou souris. Le contrat papier reste possible. L’original est conservé ; {version.layout===4?'les signatures et le cachet sont placés dans leurs cadres, sur les deux pages.':'cette ancienne mise en page utilise une bande de signatures sous chaque page. Créez une nouvelle version pour signer dans les cadres.'} Cachet provisoire EXEMPLE / TEST au nom de bensbehicarrental SARL · Tanger.</p>
 <img className="ts-stamp" src="/sample-agency-stamp.svg" alt="Cachet provisoire EXEMPLE TEST bensbehicarrental SARL Tanger"/><ol className="ts-steps"><li>Lire le contrat</li><li>Dessiner les deux signatures</li><li>Revoir le PDF et confirmer</li></ol>
 <button type="button" className="secondary" disabled={blocked} onClick={()=>{setShowOriginal(true);setOpened(true);}}>Lire le PDF original avant de signer</button>
 <label className="dp-check"><input type="checkbox" disabled={blocked||!opened} checked={read} onChange={e=>{setRead(e.target.checked);clearPreview();}}/>Le client et l’agence ont lu cette version du contrat.</label>
 <div className="ts-pads"><SignaturePad label="Signature du client" disabled={blocked||!read} onChange={updateClient}/><SignaturePad label="Signature de l’agence" disabled={blocked||!read} onChange={updateAgency}/></div>
 <div className="dp-actions"><button type="button" className="primary" disabled={blocked||!read||!client||!agency} onClick={()=>void generate()}>{busy?'Préparation…':'Préparer l’aperçu PDF signé'}</button><button type="button" className="secondary" disabled={blocked} onClick={onCancel}>Annuler la signature tablette</button></div>
 {preview&&<div className="ts-preview"><h4>Aperçu avant confirmation · deux pages A4</h4><p>Vérifiez les deux pages, les signatures et la lisibilité. La copie n’est pas encore enregistrée.</p><iframe title="Aperçu PDF avec signatures de test" src={preview.url}/><a href={preview.url} target="_blank" rel="noreferrer">Ouvrir l’aperçu PDF dans un onglet</a>
 <label className="dp-check"><input type="checkbox" disabled={blocked} checked={checked} onChange={e=>setChecked(e.target.checked)}/>J’ai revu les deux pages et les deux signatures dans cet aperçu.</label>
 <label className="dp-check"><input type="checkbox" disabled={blocked} checked={consent} onChange={e=>setConsent(e.target.checked)}/>Le client et l’agence acceptent de conserver cette copie de test.</label>
 <button type="button" className="primary" disabled={blocked||!checked||!consent} onClick={()=>void confirm()}>Confirmer et conserver la copie de test</button></div>}
 {error&&<div className="dp-error" role="alert">{error}{uncertain&&<button type="button" className="secondary" disabled={busy} onClick={()=>void confirm()}>Vérifier la même opération</button>}</div>}
 {showOriginal&&<DocumentViewer reservationId={reservation} contractFile document={{id:original.id,kind:'rental_contract',version:version.version}} onClose={()=>setShowOriginal(false)}/>}
 </section>;
}
