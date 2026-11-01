"use client";
import {ArrowUpRight} from 'lucide-react';
import {WHATSAPP_NUMBER,type ContactLanguage} from '../../lib/whatsapp';
const copy={
 en:{name:'Full name',email:'Email address',phone:'Phone',company:'Company (optional)',message:'Your message',send:'Continue on WhatsApp',note:'Your message opens in WhatsApp. Review it there before sending.'},
 fr:{name:'Nom complet',email:'Adresse e-mail',phone:'Téléphone',company:'Société (facultatif)',message:'Votre message',send:'Continuer sur WhatsApp',note:'Votre message s’ouvre dans WhatsApp. Vérifiez-le avant de l’envoyer.'},
 es:{name:'Nombre completo',email:'Correo electrónico',phone:'Teléfono',company:'Empresa (opcional)',message:'Tu mensaje',send:'Continuar en WhatsApp',note:'Tu mensaje se abre en WhatsApp. Revísalo antes de enviarlo.'},
 ar:{name:'الاسم الكامل',email:'البريد الإلكتروني',phone:'الهاتف',company:'الشركة (اختياري)',message:'الرسالة ديالك',send:'المتابعة على واتساب',note:'الرسالة غادي تفتح فواتساب. راجعها تما قبل ما تصيفطها.'}
};
export default function ContactForm({language}:{language:ContactLanguage}){const t=copy[language];return <form className="cp-form" onSubmit={event=>{event.preventDefault();const data=new FormData(event.currentTarget);const message=([['name',t.name],['email',t.email],['phone',t.phone],['company',t.company],['message',t.message]]).filter(([key])=>String(data.get(key)||'').trim()).map(([key,label])=>`${label}: ${String(data.get(key)).trim()}`).join('\n');window.open(`https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(message)}`,'_blank','noopener,noreferrer');}}>
<div className="cp-fields">{(['name','email','phone','company'] as const).map(key=><label key={key}><span>{t[key]}{key==='name'?' *':''}</span><input name={key} type={key==='email'?'email':key==='phone'?'tel':'text'} autoComplete={{name:'name',email:'email',phone:'tel',company:'organization'}[key]} required={key==='name'} maxLength={160} placeholder={t[key]}/></label>)}</div>
<label><span>{t.message} *</span><textarea name="message" required maxLength={2000} rows={4} placeholder={t.message}/></label>
<button className="cp-action" type="submit">{t.send}<ArrowUpRight size={18}/></button><p className="cp-form-note">{t.note}</p></form>}
