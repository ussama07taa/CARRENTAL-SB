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



 const plate='TEST-MAINT-'+Date.now().toString().slice(-8);
 await page.getByRole('button',{name:'Ajouter un véhicule',exact:true}).click();
 await page.locator('input[name=plate]').fill(plate);await page.getByLabel('Marque',{exact:true}).selectOption('Renault');await page.getByLabel('Modèle',{exact:true}).selectOption('Clio');await page.getByRole('button',{name:'Créer le fichier',exact:true}).click();
 const panel=page.locator('.v-maintenance'),editor=page.locator('.gf-dialog');
 const dates=await page.evaluate(()=>{const fmt=d=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Casablanca',year:'numeric',month:'2-digit',day:'2-digit'}).format(d);const add=n=>{const d=new Date();d.setDate(d.getDate()+n);return fmt(d)};return {soon:add(30),urgent:add(7)}});
 await panel.getByRole('button',{name:'Enregistrer une intervention'}).click();
 await expect(editor.locator('.m-counter-reference')).toContainText('Aucun relevé');
 await editor.locator('[name=kilometers]').fill('45000');await editor.locator('[name=amount]').fill('650,50');await editor.locator('[name=paid]').fill('200,25');
 await editor.locator('.gf-next summary').click();await editor.locator('[name=next_km]').fill('46000');await editor.locator('[name=next_on]').fill(dates.soon);await editor.locator('[name=payment_due_on]').fill(dates.urgent);
 await editor.locator('.m-options summary').click();
 const png=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=700;c.height=400;const x=c.getContext('2d');x.fillStyle='#f4f7f5';x.fillRect(0,0,700,400);x.fillStyle='#21372e';x.font='24px Arial';x.fillText('FACTURE FICTIVE - VIDANGE',30,80);return c.toDataURL('image/png').split(',')[1]});
 await editor.getByLabel('Facture du dossier',{exact:true}).setInputFiles({name:'facture-test.png',mimeType:'image/png',buffer:Buffer.from(png,'base64')});
 await page.route('**/maintenance/files',route=>route.abort('failed'));
 await editor.getByRole('button',{name:'Créer le dossier',exact:true}).click();await expect(editor.getByRole('alert')).toBeVisible();await expect(editor.getByText('facture-test.png',{exact:true})).toBeVisible();await page.unroute('**/maintenance/files');
 await editor.getByRole('button',{name:'Créer le dossier',exact:true}).click();await expect(editor.locator('.gf-table tbody tr')).toHaveCount(1);await expect(editor.locator('.m-detail-total')).toContainText('450,25');
 const download=page.waitForEvent('download');await editor.getByRole('button',{name:'Télécharger la facture initiale',exact:true}).click();assert.equal(await(await download).failure(),null);
 await editor.getByRole('button',{name:'Enregistrer un paiement'}).click();await editor.locator('[name=paid]').fill('500');await editor.getByRole('button',{name:'Enregistrer',exact:true}).click();await expect(editor.getByRole('alert')).toContainText('dépasse');
 await editor.locator('[name=paid]').fill('450,25');await editor.getByRole('button',{name:'Enregistrer',exact:true}).click();await expect(editor).toContainText('Paiement soldé');await expect(editor.locator('.gf-payment')).toHaveCount(2);
 await editor.getByRole('button',{name:'Fermer',exact:true}).click();await expect(panel.locator('.m-reminder').first()).toContainText('1.000 km');await expect(panel.locator('.m-reminder').first()).toContainText('À prévoir');
 await panel.getByRole('button',{name:'Enregistrer l’entretien effectué',exact:true}).click();await expect(editor.locator('.m-counter-reference')).toContainText('45.000 km');await expect(editor.locator('[name=kilometers]')).toHaveValue('');
 await editor.locator('[name=kilometers]').fill('46000');await editor.locator('[name=amount]').fill('700');await editor.locator('[name=paid]').fill('700');await editor.locator('.gf-next summary').click();await editor.locator('[name=next_km]').fill('56000');await editor.getByRole('button',{name:'Créer le dossier',exact:true}).click();await expect(editor).toContainText('Paiement soldé');await editor.getByRole('button',{name:'Fermer',exact:true}).click();
 await expect(panel.locator('.m-table tbody tr')).toHaveCount(2);await expect(panel.locator('.m-reminder').filter({hasText:'selon le dernier relevé'})).toHaveCount(1);
 await page.setViewportSize({width:390,height:844});await panel.getByRole('button',{name:'Enregistrer une intervention'}).click();await expect(editor.locator('.m-counter-reference')).toContainText('46.000 km');assert(await editor.evaluate(el=>el.scrollWidth<=el.clientWidth+1));
 await editor.locator('[name=amount]').fill('123');page.once('dialog',d=>d.dismiss());await editor.getByRole('button',{name:'Annuler',exact:true}).click();await expect(editor).toBeVisible();page.once('dialog',d=>d.accept());await editor.getByRole('button',{name:'Annuler',exact:true}).click();await expect(editor).toHaveCount(0);
 await page.getByRole('button',{name:'Fermer',exact:true}).click();await page.getByLabel('Rechercher un véhicule',{exact:true}).fill(plate);await page.getByRole('button',{name:'Ouvrir '+plate,exact:true}).click();await expect(panel.locator('.m-table tbody tr')).toHaveCount(2);
 assert.equal(errors.length,0,errors.join(' | '));console.log('PASS maintenance '+plate+': completed work, centimes, partial/final payments, excess rejection, upload network retry, private download, independent reminder renewal, odometer reference, mobile, discard, reopen.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
