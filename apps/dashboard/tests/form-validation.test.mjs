import test from 'node:test';
import assert from 'node:assert/strict';
import {validateFields, reportFieldErrors, localFieldError} from '../app/_lib/form-validation.ts';
class Input {
 constructor(name,value,options={}){Object.assign(this,{name,value,type:'text',step:'',dataset:{},id:'',disabled:false,willValidate:true,validity:{valid:true}},options);}
 getAttribute(key){return this[key]??null;}
}
globalThis.HTMLInputElement=Input;
const validate=(...controls)=>validateFields({querySelectorAll:()=>controls});
test('decimal number fields accept cents instead of requiring an integer',()=>{
 assert.deepEqual(validate(new Input('amount','300.50',{type:'number',step:'0.01'})),{});
});
test('integer counter rejects decimal notation and maps the public field name',()=>{
 const errors=validate(new Input('initial_odometer','46.5',{type:'number'}));
 assert.match(errors.kilometers[0],/entier/);
});
test('required and email errors belong to their inputs; disabled fields are ignored',()=>{
 const errors=validate(new Input('name','',{validity:{valueMissing:true}}),new Input('email','bad',{validity:{typeMismatch:true}}),new Input('ignored','',{disabled:true,validity:{valueMissing:true}}));
 assert.deepEqual(Object.keys(errors),['name','email']);
});
test('range validation preserves the minimum explanation',()=>{
 const errors=validate(new Input('km','12',{type:'number',min:'50',validity:{rangeUnderflow:true}}));
 assert.match(errors.km[0],/50/);
});
test('local errors dispatch the exact field to the nearest active form and preserve the message',()=>{
 const events=[],scope={};
 globalThis.document={activeElement:{closest:()=>scope},dispatchEvent:e=>events.push(e)};
 const error=localFieldError('reference','Référence obligatoire.');
 assert.equal(error.message,'Référence obligatoire.');
 assert.equal(events[0].type,'form-server-errors');
 assert.equal(events[0].detail.scope,scope);
 assert.deepEqual(events[0].detail.errors,{reference:['Référence obligatoire.']});
});
test('empty validation does not dispatch or interfere with a valid submission',()=>{
 let count=0;globalThis.document={dispatchEvent:()=>count++};
 assert.equal(reportFieldErrors({},{}),false);
 assert.equal(reportFieldErrors(null,{amount:['Erreur']}),false);
 assert.equal(count,0);
});
