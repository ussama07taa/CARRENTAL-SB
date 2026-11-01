import test from 'node:test';
import assert from 'node:assert/strict';
import {cents,dh} from '../app/expenses/types.ts';

test('French and decimal amounts preserve centimes including sensitive decimals',()=>{
 for(const [input,expected] of [['0,01',1],['0.29',29],['1.01',101],['650,50',65050],['9999999.99',999999999],['10000000',1000000000]])assert.equal(cents(input),expected);
});
test('editing stored centimes never changes them across a wide reproducible range',()=>{
 for(let i=0;i<10000;i++){const value=(i*7919)%1000000001;assert.equal(cents(dh(value)),value);}
});
test('reject ambiguous, negative, excessive precision, nonfinite and oversized inputs',()=>{
 for(const value of ['', '-1', '1.001', '1,000.50', '1 000', '1e3','NaN','Infinity','10000000.01','9007199254740991'])assert.throws(()=>cents(value));
});
