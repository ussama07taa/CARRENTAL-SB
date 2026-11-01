export type FieldErrors = Record<string, string[]>;
const aliases:Record<string,string>={make_choice:'make',model_choice:'model',seats_choice:'seats',initial_odometer:'kilometers',initial_odometer_date:'recorded_on'};
export function fieldName(control:HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement|HTMLButtonElement){const name=control.name||control.dataset.validationField||control.id.replace(/^client-/, '');return aliases[name]??name;}
export function validateFields(form:HTMLFormElement):FieldErrors{
 const errors:FieldErrors={};
 for(const control of Array.from(form.querySelectorAll<HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement>('input,select,textarea'))){
 if(control.disabled||!control.willValidate)continue;
 const name=fieldName(control);if(!name)continue;
 const v=control.validity;let message='';
 if(v.valueMissing)message=control.type==='checkbox'?'Confirmez ce choix pour continuer.':'Renseignez ce champ.';
 else if(control.value&&control instanceof HTMLInputElement&&control.type==='number'&&(!control.step||control.step==='1')&&!/^\d+$/.test(control.value))message='Saisissez un nombre entier sans point ni virgule. Exemple : 46000.';
 else if(v.typeMismatch)message=control.type==='email'?'Saisissez une adresse e-mail valide. Exemple : nom@exemple.ma.':'Vérifiez le format de ce champ.';
 else if(v.rangeUnderflow)message='La valeur doit être au moins '+control.getAttribute('min')+'.';
 else if(v.rangeOverflow)message='La valeur ne doit pas dépasser '+control.getAttribute('max')+'.';
 else if(v.stepMismatch)message='Saisissez un nombre entier sans décimales.';
 else if(v.patternMismatch)message='Respectez le format indiqué pour ce champ.';
 else if(!v.valid)message='Vérifiez la valeur de ce champ.';
 if(message)errors[name]=[message];
 }
 return errors;
}
export function focusFieldError(form:HTMLFormElement|null,errors:FieldErrors){
 if(!form)return;
 const controls=Array.from(form.querySelectorAll<HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement>('input,select,textarea'));
 const candidates=controls.filter(el=>errors[fieldName(el)]?.length&&!el.disabled);const first=candidates[0];const control=first?(candidates.find(el=>el.name===fieldName(first))??first):undefined;
 if(!control)return;
 let parent=control.parentElement;while(parent&&parent!==form){if(parent instanceof HTMLDetailsElement)parent.open=true;parent=parent.parentElement;}
 requestAnimationFrame(()=>{control.scrollIntoView({block:'center'});control.focus({preventScroll:true});});
}

/** Report explicit local checks through the same accessible field feedback as API errors. */
export function reportFieldErrors(scope:HTMLElement|null,errors:FieldErrors){
 if(!scope||!Object.keys(errors).length)return false;
 document.dispatchEvent(new CustomEvent('form-server-errors',{detail:{scope,errors}}));
 return true;
}

/** Associate a local validation failure with the control before the caller displays its alert. */
export function localFieldError(field:string,message:string):Error{
 const scope=document.activeElement?.closest<HTMLElement>('form,section,dialog,[role="dialog"]')??null;
 reportFieldErrors(scope,{[field]:[message]});
 return new Error(message);
}
