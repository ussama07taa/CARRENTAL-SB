"use client";
import {useEffect} from 'react';
import {fieldName} from '../_lib/form-validation';
type Control=HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement|HTMLButtonElement;
let sequence=0;
export function revealError(control:HTMLElement){let p=control.parentElement;while(p){if(p instanceof HTMLDetailsElement)p.open=true;p=p.parentElement;}requestAnimationFrame(()=>{if(!control.isConnected)return;control.scrollIntoView({block:'center'});control.focus({preventScroll:true});});}
export function showControlError(control:Control,message:string){
 if(control.getAttribute('aria-invalid')==='true'&&!control.dataset.feedbackOwned)return;
 let id=control.dataset.feedbackOwned;
 if(!id||!document.getElementById(id)){id=id||'form-feedback-'+(++sequence);control.dataset.feedbackOwned=id;const text=document.createElement('span');text.id=id;text.className='form-field-feedback';control.insertAdjacentElement('afterend',text);control.setAttribute('aria-describedby',Array.from(new Set((control.getAttribute('aria-describedby')??'').split(' ').concat(id).filter(Boolean))).join(' '));}
 document.getElementById(id)!.textContent=message;control.setAttribute('aria-invalid','true');
}
function clear(control:Control){const id=control.dataset.feedbackOwned;if(!id)return;document.getElementById(id)?.remove();control.setAttribute('aria-describedby',(control.getAttribute('aria-describedby')??'').split(' ').filter(x=>x!==id).join(' '));control.removeAttribute('aria-invalid');delete control.dataset.feedbackOwned;}
export default function FormFeedback(){useEffect(()=>{
 let frame=0;let first:HTMLElement|null=null;
 const invalid=(event:Event)=>{const el=event.target;if(!(el instanceof HTMLInputElement||el instanceof HTMLSelectElement||el instanceof HTMLTextAreaElement))return;event.preventDefault();showControlError(el,el.validity.valueMissing?(el.type==='checkbox'?'Confirmez ce choix pour continuer.':'Renseignez ce champ.'):el.validity.typeMismatch?'Vérifiez le format de ce champ.':el.validity.rangeUnderflow?`La valeur doit être au moins ${el.getAttribute('min')}.`:el.validity.rangeOverflow?`La valeur ne doit pas dépasser ${el.getAttribute('max')}.`:el.validationMessage||'Vérifiez ce champ.');if(!first)first=el;cancelAnimationFrame(frame);frame=requestAnimationFrame(()=>{if(first)revealError(first);first=null;});};
 const submit=(event:Event)=>{
  const form=event.target;if(!(form instanceof HTMLFormElement))return;
  let target:Control|undefined;
  for(const control of form.querySelectorAll<HTMLInputElement>('input[inputmode="decimal"]')){
   if(control.disabled||!control.value.trim())continue;
   if(! /^-?\d+([.,]\d{1,2})?$/.test(control.value.trim())){
    showControlError(control,'Saisissez un montant en DH, par exemple 300 ou 300,50.');target??=control;
   }
  }
  if(target){event.preventDefault();event.stopImmediatePropagation();revealError(target);}
 };
 const input=(event:Event)=>{if(event.target instanceof HTMLInputElement||event.target instanceof HTMLSelectElement||event.target instanceof HTMLTextAreaElement)clear(event.target);};
 const server=(event:Event)=>{const {scope,errors}= (event as CustomEvent<{scope:HTMLElement;errors:Record<string,string[]>}>).detail;if(!scope?.isConnected)return;let target:Control|undefined;for(const control of scope.querySelectorAll<Control>('input,select,textarea,button[data-validation-field]')){const key=fieldName(control);const alternatives:Record<string,string[]>={amount:['amount_centimes'],date:['received_on','paid_on'],km:['kilometers'],fuel:['fuel_eighths'],end:['ends_local'],start:['starts_local'],attested:['receipt_attested'],checked:['checked'],verify_reason:['reason'],paid_date:['paid_on'],due:['due_on']};
 const serverKey=errors[key]?key:(alternatives[key]??[]).find(k=>errors[k]);
 const message=serverKey?errors[serverKey]:undefined;if(message?.length){showControlError(control,Array.isArray(message)?message.join(' '):String(message));if(!target&&control.type!=='hidden'&&control.getClientRects().length)target=control;}}if(target){setTimeout(()=>revealError(target!),0);}};
 let interactionAt=0;let activeScope:Element|null=null;const announcements=new WeakMap<Element,string>();
 const interaction=(event:Event)=>{const target=event.target;const fieldButton=target instanceof Element?target.closest<HTMLButtonElement>('button[data-validation-field]'):null;if(fieldButton)clear(fieldButton);if(target instanceof Element&&target.closest('button,input[type=submit]')){interactionAt=Date.now();activeScope=target.closest('form,dialog,[role=dialog],section');}};
 const observer=new MutationObserver(()=>{if(!activeScope?.isConnected||Date.now()-interactionAt>20000)return;
 for(const alert of activeScope.querySelectorAll<HTMLElement>('[role="alert"]')){const text=alert.textContent?.trim()??'';if(!text||announcements.get(alert)===text||!alert.getClientRects().length)continue;announcements.set(alert,text);
 const invalidControl=activeScope.querySelector<HTMLElement>('input[aria-invalid="true"],select[aria-invalid="true"],textarea[aria-invalid="true"],button[data-validation-field][aria-invalid="true"]');
 if(invalidControl)revealError(invalidControl);else{alert.tabIndex=-1;revealError(alert);}break;}});
 observer.observe(document.body,{childList:true,subtree:true,characterData:true});document.addEventListener('click',interaction,true);
 document.addEventListener('submit',submit,true);document.addEventListener('invalid',invalid,true);document.addEventListener('input',input,true);document.addEventListener('change',input,true);document.addEventListener('form-server-errors',server);
 return()=>{observer.disconnect();document.removeEventListener('click',interaction,true);cancelAnimationFrame(frame);document.removeEventListener('submit',submit,true);document.removeEventListener('invalid',invalid,true);document.removeEventListener('input',input,true);document.removeEventListener('change',input,true);document.removeEventListener('form-server-errors',server);};
 },[]);return null;}
