 'use client';
import {useEffect,useRef,useState,type FormEvent} from 'react';
import {Eye,Pencil,KeyRound,X} from 'lucide-react';
import {apiMutation} from '../_lib/auth-client';
export type EditableMember={id:number;name:string;email:string;role:string;active:boolean;can_toggle:boolean;is_self:boolean};
export type EditorMode='view'|'edit'|'password';
export default function UserEditor({member,mode,onClose,onSaved}:{member:EditableMember;mode:EditorMode;onClose:()=>void;onSaved:()=>void}){
 const dialog=useRef<HTMLDialogElement>(null),lock=useRef(false);const [busy,setBusy]=useState(false),[error,setError]=useState('');
 useEffect(()=>{const origin=document.activeElement as HTMLElement;dialog.current?.showModal();return()=>origin?.focus();},[]);
 const title=mode==='view'?'Fiche utilisateur':mode==='edit'?'Modifier l’utilisateur':'Réinitialiser le mot de passe';
 async function save(e:FormEvent<HTMLFormElement>){e.preventDefault();if(lock.current||mode==='view')return;lock.current=true;setBusy(true);setError('');const values=new FormData(e.currentTarget);
 try{const payload=mode==='edit'?{name:values.get('name'),email:values.get('email'),role:values.get('role'),reason:values.get('reason')}:{password:values.get('password'),password_confirmation:values.get('password_confirmation'),reason:values.get('reason')};
 const response=await apiMutation('employees/'+member.id+(mode==='edit'?'/profile':'/password'),mode==='edit'?'PUT':'POST',payload);
 if(!response.ok){const body=await response.json();throw Error(Object.values(body.errors??{}).flat().join(' ')||body.message||'Modification impossible.');}onSaved();
 }catch(e){setError((e as Error).message)}finally{lock.current=false;setBusy(false)}}
 return <dialog ref={dialog} className="team-dialog" aria-labelledby="user-editor-title" onCancel={e=>{e.preventDefault();if(!lock.current)onClose();}}><form onSubmit={save}>
 <header><span className="team-dialog-icon">{mode==='view'?<Eye size={22}/>:mode==='edit'?<Pencil size={22}/>:<KeyRound size={22}/>}</span><button type="button" aria-label="Fermer le fichier utilisateur" disabled={busy} onClick={onClose}><X size={20}/></button><h2 id="user-editor-title">{title}</h2><p>{member.name}</p></header>
 <div className="team-dialog-body">{error&&<div className="team-error" role="alert">{error}</div>}
 {mode==='view'?<dl className="team-user-details"><div><dt>Nom complet</dt><dd>{member.name}</dd></div><div><dt>Adresse e-mail</dt><dd>{member.email}</dd></div><div><dt>Rôle</dt><dd>{member.role==='responsable'?'Gérant':'Employé'}</dd></div><div><dt>État du compte</dt><dd>{member.active?'Actif':'Désactivé'}</dd></div></dl>:<>
 {mode==='edit'?<><label>Nom complet<input name="name" defaultValue={member.name} required maxLength={150} autoFocus/></label><label>Adresse e-mail<input name="email" type="email" defaultValue={member.email} required maxLength={254}/></label><label>Rôle<select aria-label="Rôle" name="role" defaultValue={member.role}><option value="employe" disabled={!member.can_toggle}>Employé</option><option value="responsable">Gérant</option></select></label>{!member.can_toggle&&<p className="team-form-help">Le dernier gérant actif doit conserver son rôle.</p>}<p className="team-form-help">Un gérant accède aux opérations, aux finances et à la gestion des utilisateurs. Un changement de rôle ou d’e-mail ferme les sessions de ce compte.</p></>:<><label>Nouveau mot de passe<input name="password" type="password" autoComplete="new-password" required minLength={12} maxLength={128} autoFocus/><small>12 caractères minimum.</small></label><label>Confirmer le mot de passe<input name="password_confirmation" type="password" autoComplete="new-password" required minLength={12} maxLength={128}/></label><p className="team-form-help">Les sessions de ce compte seront fermées. Transmettez le nouveau mot de passe directement à l’utilisateur ; aucun e-mail n’est envoyé automatiquement.</p></>}
 <label>Motif<textarea name="reason" required minLength={3} maxLength={250} placeholder="Pourquoi effectuez-vous ce changement ?"/></label>
 </>}
 </div><footer><button type="button" className="team-secondary" disabled={busy} onClick={onClose}>{mode==='view'?'Fermer':'Annuler'}</button>{mode!=='view'&&<button className="team-primary" disabled={busy}>{busy?'Enregistrement…':mode==='edit'?'Enregistrer les modifications':'Réinitialiser le mot de passe'}</button>}</footer>
 </form></dialog>
}
