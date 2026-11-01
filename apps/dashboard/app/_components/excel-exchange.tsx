"use client";
import {useRef,useState} from 'react';
import {Download,Upload,FileSpreadsheet,X,CheckCircle2,AlertCircle} from 'lucide-react';
import {api,apiMutation} from '../_lib/auth-client';
import './excel-exchange.css';
type Preview={columns:Record<string,string>;rows:{line:number;data:Record<string,string|null>;errors:string[]}[];invalid:number;token:string|null};
const displayValue=(key:string,value:string|null)=>{if(!value)return '—';if(/^\d{4}-\d{2}-\d{2}$/.test(value))return value.split('-').reverse().join('/');const labels:Record<string,Record<string,string>>={fuel:{petrol:'Essence',diesel:'Diesel',hybrid:'Hybride',electric:'Électrique'},transmission:{manual:'Manuelle',automatic:'Automatique'},language:{fr:'Français',ar:'Arabe',en:'Anglais',es:'Espagnol'},identity_type:{cin:'CIN',passport:'Passeport'},kind:{oil:'Vidange',brakes:'Freins',tires:'Pneus',battery:'Batterie',engine:'Moteur',body:'Carrosserie',service:'Entretien',repair:'Réparation'}};return labels[key]?.[value]??value;};
export default function ExcelExchange({kind,filters,onImported}:{kind:'vehicles'|'customers'|'maintenance';filters:Record<string,string>;onImported:()=>void}){
 const dialog=useRef<HTMLDialogElement>(null),input=useRef<HTMLInputElement>(null);
 const [file,setFile]=useState<File|null>(null),[preview,setPreview]=useState<Preview|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState('');
 async function download(template=false){setBusy(true);setError('');try{
  const params=new URLSearchParams(Object.entries(filters).filter(([,v])=>v));
  const response=await fetch(`${api}/api/v1/excel/${kind}/${template?'template':'export'}?${params}`,{credentials:'include',headers:{Accept:'application/json'},cache:'no-store'});
  if(!response.ok){const data=await response.json();throw new Error(Object.values(data.errors??{}).flat().join(' ')||data.message||'Téléchargement impossible.');}
  const url=URL.createObjectURL(await response.blob()),a=document.createElement('a');a.href=url;a.download=`${template?'modele-':''}${kind}.xlsx`;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
 }catch(e){setError(e instanceof Error?e.message:'Téléchargement impossible.');}finally{setBusy(false);}}
 async function analyze(){if(!file)return;setBusy(true);setError('');setPreview(null);try{
  const body=new FormData();body.append('file',file);const response=await apiMutation(`excel/${kind}/preview`,'POST',body);const data=await response.json();
  if(!response.ok)throw new Error(Object.values(data.errors??{}).flat().join(' ')||data.message||'Analyse impossible.');setPreview(data);
 }catch(e){setError(e instanceof Error?e.message:'Analyse impossible.');}finally{setBusy(false);}}
 async function confirm(){if(!preview?.token)return;setBusy(true);setError('');try{
  const response=await apiMutation(`excel/${kind}/confirm`,'POST',{token:preview.token});const data=await response.json();
  if(!response.ok){if(response.status===409||response.status===422)setPreview(null);throw new Error(Object.values(data.errors??{}).flat().join(' ')||data.message||'Import impossible.');}
  setNotice(data.message);setPreview(null);setFile(null);dialog.current?.close();onImported();
 }catch(e){setError(e instanceof Error?e.message:'Import impossible. Vous pouvez réessayer sans créer de doublons.');}finally{setBusy(false);}}
 function open(){setError('');setNotice('');setPreview(null);setFile(null);if(input.current)input.current.value='';dialog.current?.showModal();}
 return <div className="excel-tools"><button type="button" className="secondary" disabled={busy} onClick={()=>void download()} title="Exporter toutes les lignes correspondant aux filtres"><Download size={16}/> Exporter Excel</button><button type="button" className="secondary" disabled={busy} onClick={open}><Upload size={16}/> Importer Excel</button>
 {notice&&<span className="excel-notice" role="status">{notice}</span>}{error&&!dialog.current?.open&&<span role="alert" className="excel-error">{error}</span>}
 <dialog ref={dialog} className="excel-dialog" onCancel={e=>{if(busy)e.preventDefault();}} aria-labelledby={`excel-title-${kind}`}>
 <header><div><FileSpreadsheet size={22}/><h2 id={`excel-title-${kind}`}>Importer des {kind==='vehicles'?'véhicules':kind==='maintenance'?'interventions terminées':'clients'}</h2></div><button type="button" className="excel-close" aria-label="Fermer l’import Excel" disabled={busy} onClick={()=>dialog.current?.close()}><X size={20}/></button></header>
 <div className="excel-body"><ol className="excel-steps"><li>1. Modèle Excel</li><li>2. Vérification</li><li>3. Confirmation</li></ol>
 <p>{kind==='maintenance'?'Ajoutez l’historique des travaux terminés sur les voitures de votre parc. Aucun dossier existant ne sera remplacé.':'Ajoutez de nouveaux fichiers. Aucun fichier existant ne sera remplacé.'} Les erreurs et doublons bloquent l’ensemble de l’import.</p>
 <button type="button" className="secondary" disabled={busy} onClick={()=>void download(true)}><Download size={16}/> Télécharger le modèle</button>
 <p className="excel-help">Remplissez la première feuille, sans changer les en-têtes. Consultez l’onglet Instructions du modèle. Aucun prix ni paiement n’est importé.</p>{kind==='maintenance'&&<p className="excel-help">Le coût reste « Non défini ». Le compteur actuel et la disponibilité de la voiture restent inchangés. Une ligne correspond à une intervention terminée.</p>}
 <label className="excel-file">Fichier Excel (.xlsx)<input ref={input} aria-label="Fichier Excel (.xlsx)" className="sr-only" type="file" accept=".xlsx" disabled={busy} onChange={e=>{setFile(e.target.files?.[0]??null);setPreview(null);setError('');}}/><span className="excel-file-picker"><button type="button" className="secondary" disabled={busy} onClick={()=>input.current?.click()}>Choisir un fichier</button><span>{file?.name??'Aucun fichier sélectionné'}</span></span></label><p className="excel-help">500 lignes maximum · 2 Mo · dates jj/mm/aaaa</p>
 {error&&<p role="alert" className="excel-error"><AlertCircle size={18}/>{error}</p>}
 {preview&&<section aria-label="Résultat de la vérification"><h3>{preview.invalid?<><AlertCircle size={18}/> {preview.invalid} ligne(s) à corriger</>:<><CheckCircle2 size={18}/> {preview.rows.length} ligne(s) prête(s) à importer</>}</h3><p>{preview.invalid?'Corrigez le fichier puis analysez-le à nouveau. Rien n’a été enregistré.':'Vérifiez les données ci-dessous, puis confirmez. Cet aperçu est valable 30 minutes.'}</p>
 <div className="excel-table" tabIndex={0} role="region" aria-label="Aperçu des lignes Excel"><table><thead><tr><th>Ligne</th><th>Vérification</th>{Object.entries(preview.columns).map(([key,label])=><th key={key}>{label}</th>)}</tr></thead><tbody>{preview.rows.map(row=><tr key={row.line}><td>{row.line}</td><td>{row.errors.length?<ul>{row.errors.map((e,i)=><li key={i}>{e}</li>)}</ul>:<span>Prêt à créer</span>}</td>{Object.keys(preview.columns).map(key=><td key={key}>{displayValue(key,row.data[key])}</td>)}</tr>)}</tbody></table></div></section>}
 </div><footer><button type="button" className="secondary" disabled={busy} onClick={()=>dialog.current?.close()}>Annuler</button>{preview?.token?<button type="button" className="primary" disabled={busy} onClick={()=>void confirm()}>{busy?'Enregistrement…':`Confirmer l’import (${preview.rows.length})`}</button>:<button type="button" className="primary" disabled={busy||!file} onClick={()=>void analyze()}>{busy?'Analyse…':'Analyser le fichier'}</button>}</footer>
 </dialog></div>;
}
