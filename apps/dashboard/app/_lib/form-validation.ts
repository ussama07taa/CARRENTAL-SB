export type FieldErrors = Record<string, string[]>;
const aliases:Record<string,string>={make_choice:'make',model_choice:'model',seats_choice:'seats',initial_odometer:'kilometers',initial_odometer_date:'recorded_on'};
export function fieldName(control:HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement){const name=control.name||control.id.replace(/^client-/, '');return aliases[name]??name;}
export function validateFields(form:HTMLFormElement):FieldErrors{
 const errors:FieldErrors={};
 for(const control of Array.from(form.querySelectorAll<HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement>('input,select,textarea'))){
 if(control.disabled||!control.willValidate)continue;
 const name=fieldName(control);if(!name)continue;
 const v=control.validity;let message='';
 if(v.valueMissing)message=control.type==='checkbox'?'Confirmez ce choix pour continuer.':'Renseignez ce champ.';
 else if(control.value&&control instanceof HTMLInputElement&&control.type==='number'&&control.step!=='any'&&!/^\d+$/.test(control.value))message='Saisissez un nombre entier sans point ni virgule. Exemple : 46000.';
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
