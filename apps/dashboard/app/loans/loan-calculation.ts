export type LoanSplit = {count:number;regular:number;last:number;amounts:number[]};

/** Split principal only. Any bank fees or interest must come from its actual schedule. */
export function splitFinancing(total:number,basis:'count'|'amount',value:number):LoanSplit{
 if(!Number.isSafeInteger(total)||total<1||total>1_000_000_000)throw Error('Vérifiez le montant financé à l’étape précédente.');
 if(!Number.isSafeInteger(value)||value<1)throw Error(basis==='count'?'Indiquez un nombre de mois entier.':'Indiquez une mensualité positive.');
 let count:number,regular:number;
 if(basis==='count'){
  if(value>360)throw Error('Choisissez entre 1 et 360 mois.');
  count=value;regular=Math.floor(total/count);
  if(regular<1)throw Error('Réduisez le nombre de mois pour ce montant.');
 }else{
  if(value>total)throw Error('La mensualité ne peut pas dépasser le montant à répartir.');
  regular=value;count=Math.ceil(total/regular);
  if(count>360)throw Error('Ce montant dépasse 360 mensualités. Augmentez la mensualité.');
 }
 const last=total-regular*(count-1);
 return {count,regular,last,amounts:Array.from({length:count},(_,i)=>i===count-1?last:regular)};
}

export function inputMoney(centimes:number):string{
 return Math.floor(centimes/100)+'.'+String(centimes%100).padStart(2,'0');
}
