export function parseCentimes(value:string):number|null {
 if(!/^\d{1,7}([.,]\d{1,2})?$/.test(value.trim()))return null;
 const [units,fraction='']=value.trim().split(/[.,]/);
 return Number(units)*100+Number(fraction.padEnd(2,'0'));
}
export function rentalDays(start:string,end:string):number {
 const count=(Date.parse(end+'T12:00:00Z')-Date.parse(start+'T12:00:00Z'))/86400000;
 return Number.isInteger(count)&&count>0?count:0;
}
export function formatMoney(cents:number){return new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(cents/100)+' DH';}
