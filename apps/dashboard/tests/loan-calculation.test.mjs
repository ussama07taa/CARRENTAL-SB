import test from 'node:test';
import assert from 'node:assert/strict';
import {splitFinancing,inputMoney} from '../app/loans/loan-calculation.ts';

test('purchase minus apport splits over the chosen duration, excluding the apport',()=>{
 const p=splitFinancing(20000000-5000000,'count',50);
 assert.equal(p.count,50);assert.equal(p.regular,300000);assert.equal(p.last,300000);
 assert.equal(p.amounts.reduce((a,b)=>a+b,0),15000000);
});
test('100000 DH at 1500 DH per month gives 66 full payments and a final 1000 DH',()=>{
 const p=splitFinancing(10000000,'amount',150000);
 assert.equal(p.count,67);assert.equal(p.amounts.filter(x=>x===150000).length,66);
 assert.equal(p.last,100000);assert.equal(p.amounts.reduce((a,b)=>a+b,0),10000000);
});
test('a division remainder is settled in the last installment, with no extra centime',()=>{
 const p=splitFinancing(10000000,'count',24);
 assert.equal(p.regular,416666);assert.equal(p.last,416682);
 assert.equal(p.amounts.reduce((a,b)=>a+b,0),10000000);
});
test('single month and exact monthly divisor do not add an extra installment',()=>{
 assert.deepEqual(splitFinancing(12345,'count',1).amounts,[12345]);
 assert.deepEqual(splitFinancing(900000,'amount',300000).amounts,[300000,300000,300000]);
 assert.equal(inputMoney(12345),'123.45');assert.equal(inputMoney(1),'0.01');
});
test('all supported month counts preserve exact integer money',()=>{
 for(const total of [361,10001,10000000,999999999,1000000000])
  for(let count=1;count<=360;count++){
   const p=splitFinancing(total,'count',count);
   assert.equal(p.amounts.length,count);
   assert.equal(p.amounts.reduce((a,b)=>a+b,0),total);
   assert.ok(p.amounts.every(x=>Number.isSafeInteger(x)&&x>0));
  }
});
test('invalid duration, money or unaffordable duration does not generate a schedule',()=>{
 for(const [total,basis,value] of [[0,'count',24],[-1,'count',1],[100.2,'count',1],[1000000001,'count',1],[100,'count',0],[100,'count',2.5],[100,'count',361],[10,'count',24],[100,'amount',0],[100,'amount',101],[100,'amount',1.1],[10000000,'amount',1],[100,'count',NaN],[100,'amount',Infinity]])
  assert.throws(()=>splitFinancing(total,basis,value));
});
