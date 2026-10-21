const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {expect}=require((process.env.PLAYWRIGHT_MODULE||'playwright')+'/test');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const local=path.resolve(__dirname,'../.local');
const credentials=JSON.parse(fs.readFileSync(path.join(local,'demo-account.json'),'utf8'));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
 const context=await browser.newContext({viewport:{width:1440,height:1000}});
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://localhost:3000/login');
 await page.getByLabel('Adresse e-mail',{exact:true}).fill(credentials.email);
 await page.getByLabel('Mot de passe',{exact:true}).fill(credentials.password);
 await page.getByRole('button',{name:'Se connecter',exact:true}).click();
 await expect(page).toHaveURL('http://localhost:3000/');
 await page.getByRole('link',{name:'Véhicules',exact:true}).click();
 await expect(page.getByRole('button',{name:'Ajouter un véhicule',exact:true})).toBeVisible();




 const samples=[{title:'Prix confirmé payé',cost_centimes:70000,paid_centimes:70000,balance_centimes:0,cost_final:true,credit_centimes:0,unknown_count:0},{title:'Prix estimatif payé',cost_centimes:570000,paid_centimes:570000,balance_centimes:0,cost_final:false,credit_centimes:0,unknown_count:0},{title:'Paiement partiel',cost_centimes:65000,paid_centimes:20000,balance_centimes:45000,cost_final:true,credit_centimes:0,unknown_count:0},{title:'Prix inconnu avec avance',cost_centimes:0,paid_centimes:20000,balance_centimes:0,cost_final:false,credit_centimes:20000,unknown_count:1},{title:'Trop versé confirmé',cost_centimes:80000,paid_centimes:100000,balance_centimes:0,cost_final:true,credit_centimes:20000,unknown_count:0}];
 await page.route('**/maintenance?*',async route=>{const response=await route.fetch();const body=await response.json();body.data=samples.map((sample,i)=>({id:900+i,kind:'oil',performed_on:'2026-10-17',kilometers:46000,garage:'Garage fictif',...sample}));body.total=5;body.page=1;body.last_page=1;await route.fulfill({response,json:body});});
 await page.getByLabel('Rechercher un véhicule',{exact:true}).fill('TEST-GARAGE-7426844');await page.getByRole('button',{name:'Ouvrir TEST-GARAGE-7426844',exact:true}).click();const table=page.locator('.m-table');await expect(table.locator('tbody tr')).toHaveCount(5);
 await expect(table.locator('thead')).toContainText('Total');await expect(table.locator('thead')).toContainText('Déjà payé');await expect(table.locator('thead')).toContainText('État du prix');
 const row=name=>table.locator('tbody tr').filter({hasText:name});
 await expect(row('Prix confirmé payé')).toContainText('Tout est payé');await expect(row('Prix confirmé payé')).toContainText('Confirmé');
 await expect(row('Prix estimatif payé')).toContainText('Montant connu entièrement payé');await expect(row('Prix estimatif payé')).toContainText('Estimatif');await expect(row('Prix estimatif payé').locator('td').nth(4)).toContainText('0,00 DH');
 await expect(row('Paiement partiel').locator('td').nth(3)).toContainText('200,00 DH');await expect(row('Paiement partiel').locator('td').nth(4)).toContainText('450,00 DH');
 await expect(row('Prix inconnu avec avance')).toContainText('À compléter');await expect(row('Prix inconnu avec avance')).toContainText('Avance au-delà du connu');await expect(row('Prix inconnu avec avance')).not.toContainText('entièrement payé');await expect(row('Trop versé confirmé')).toContainText('Trop-versé : 200,00 DH');
 await table.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(local,'maintenance-table-clear-desktop.png')});await page.setViewportSize({width:390,height:844});await table.scrollIntoViewIfNeeded();const wrap=page.locator('.m-table-wrap');assert(await page.locator('.vehicle-dialog').evaluate(el=>el.scrollWidth<=el.clientWidth+1));await wrap.evaluate(el=>el.scrollLeft=el.scrollWidth);await page.screenshot({path:path.join(local,'maintenance-table-clear-mobile.png')});
 assert.equal(errors.length,0,errors.join(' | '));console.log('PASS maintenance table: confirmed paid, estimated paid, partial payment, unknown with advance, overpayment, separate columns, mobile scroll, no data mutations.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
