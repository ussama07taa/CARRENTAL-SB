'use client';
import {validateFields,focusFieldError} from '../_lib/form-validation';
import FieldHelp from '../_components/field-help';
import { useState, type FormEvent } from 'react';
import { authPost } from '../_lib/auth-client';

export default function LoginForm() {
  const [fieldErrors,setFieldErrors]=useState<Record<string,string[]>>({});
  const [busy, setBusy] = useState(false);
  const [visible, setVisible] = useState(false);
  const [error, setError] = useState('');
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;const target=event.currentTarget;const invalid=validateFields(target);setFieldErrors(invalid);if(Object.keys(invalid).length){setError('Vérifiez les champs signalés ci-dessous.');focusFieldError(target,invalid);return;}
    const data = new FormData(event.currentTarget);
    setBusy(true); setError('');
    try {
      const response = await authPost('login', { email: String(data.get('email') ?? ''), password: String(data.get('password') ?? '') });
      if (response.ok) { window.location.replace('/'); return; }
      const payload = await response.json().catch(() => ({}));
      if(payload.errors){setFieldErrors(payload.errors);setTimeout(()=>focusFieldError(target,payload.errors),0);}
      const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
      setError(response.status === 419 ? 'La session a expiré. Cliquez de nouveau sur Se connecter.' : response.status >= 500 ? 'Le service est momentanément indisponible. Réessayez.' : String(validation ?? payload.message ?? 'Connexion impossible. Réessayez.'));
    } catch {
      setError('Connexion au serveur impossible. Vérifiez votre connexion et réessayez.');
    } finally { setBusy(false); }
  }
  return <form noValidate onSubmit={submit} aria-busy={busy}>
    <div className="field"><label htmlFor="email">Adresse e-mail<FieldHelp field="email" label="Adresse e-mail"/></label><input aria-label="Adresse e-mail" aria-invalid={!!fieldErrors.email?.length} onChange={()=>setFieldErrors(v=>({...v,email:[]}))} id="email" name="email" type="email" autoComplete="username" placeholder="vous@exemple.ma" required maxLength={255} autoCapitalize="none" spellCheck={false} aria-describedby={fieldErrors.email?.length?"login-email-error":error?"login-error":undefined} />{fieldErrors.email?.[0]&&<small className="field-error" id="login-email-error">{fieldErrors.email[0]}</small>}</div>
    <div className="field"><label htmlFor="password">Mot de passe<FieldHelp field="password" label="Mot de passe"/></label><div className="password-field"><input aria-label="Mot de passe" aria-invalid={!!fieldErrors.password?.length} onChange={()=>setFieldErrors(v=>({...v,password:[]}))} id="password" name="password" type={visible ? 'text' : 'password'} autoComplete="current-password" required maxLength={1024} aria-describedby={fieldErrors.password?.length?"login-password-error":error?"login-error":undefined} /><button type="button" className="reveal" aria-pressed={visible} aria-controls="password" onClick={() => setVisible(!visible)}>{visible ? 'Masquer' : 'Afficher'}</button></div>{fieldErrors.password?.[0]&&<small className="field-error" id="login-password-error">{fieldErrors.password[0]}</small>}</div>
    {error && <p className="notice error" id="login-error" role="alert">{error}</p>}
    <button type="submit" className="primary full" disabled={busy}>{busy ? 'Connexion en cours…' : 'Se connecter'}<span aria-hidden="true">→</span></button>
    <p className="form-help">Un problème d’accès ? Contactez le responsable de l’agence.</p>
  </form>;
}
