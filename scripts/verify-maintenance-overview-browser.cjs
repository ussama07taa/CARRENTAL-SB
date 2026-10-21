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
 await page.getByRole('link',{name:'Entretien & suivi',exact:true}).click();await expect(page).toHaveURL('http://localhost:3000/maintenance');await expect(page.getByRole('heading',{name:'Entretien & suivi',exact:true})).toBeVisible();
 await page.getByRole('button',{name:/Tout le suivi/}).click();await expect(page.locator('.mo-table tbody tr').first()).toBeVisible();
 await page.getByLabel('Rechercher une voiture',{exact:true}).fill('TEST-GARAGE-0273002');await expect(page.locator('.mo-table tbody tr')).toHaveCount(2);await expect(page.locator('.mo-table')).toContainText('TEST-GARAGE-0273002');
 await page.getByRole('button',{name:/Paiements restants/}).click();await expect(page.locator('.mo-table tbody tr')).toHaveCount(1);
 const before=Number((await page.locator('.mo-balance').innerText()).replaceAll('.','').replaceAll(' DH','').replace(',','.'));await page.getByRole('button',{name:/Enregistrer un paiement · TEST-GARAGE-0273002/}).click();const form=page.locator('.gf-dialog');await expect(form.locator('.fleet-eyebrow')).toContainText('TEST-GARAGE-0273002');await expect(form.locator('.gf-operation')).toContainText('Montant réellement payé');await form.getByLabel('Montant réellement payé (DH)',{exact:true}).fill('1');await form.locator('.gf-operation').getByRole('button',{name:'Enregistrer',exact:true}).click();await expect(form.locator('.gf-operation')).toHaveCount(0);await form.getByRole('button',{name:'Fermer',exact:true}).click();await expect(page.locator('.mo-balance')).toHaveText(new Intl.NumberFormat('fr-MA',{minimumFractionDigits:2}).format(before-1)+' DH');
 await page.getByRole('button',{name:/Tout le suivi/}).click();await page.getByRole('button',{name:/Enregistrer l’entretien · TEST-GARAGE-0273002/}).click();await expect(form).toContainText('Ajouter une intervention');await expect(form.getByLabel('Travaux à effectuer / effectués',{exact:true})).toHaveValue('Vidange');await form.getByRole('button',{name:'Annuler',exact:true}).click();
 await page.getByLabel('Rechercher une voiture',{exact:true}).fill('TEST-GARAGE-7426844');await page.getByRole('button',{name:/Travaux reportés/}).click();await expect(page.locator('.mo-table tbody tr')).toHaveCount(1);await page.getByRole('button',{name:/Suivre le travail ·/}).click();await expect(form.locator('.gf-operation')).toContainText('Suivre :');await form.getByRole('button',{name:'Fermer',exact:true}).click();
 await page.getByLabel('Rechercher une voiture',{exact:true}).fill('NOT-EXISTING-TEST');await expect(page.locator('.mo-empty')).toBeVisible();await page.getByRole('button',{name:'Voir tout le suivi',exact:true}).click();await expect(page.locator('.mo-table tbody tr').first()).toBeVisible();
 await page.screenshot({path:path.join(local,'maintenance-overview-desktop.png')});await page.setViewportSize({width:390,height:844});await page.locator('.mo-tabs').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(local,'maintenance-overview-mobile.png')});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));await page.locator('.mo-table tbody tr').first().scrollIntoViewIfNeeded();await expect(page.locator('.mo-table tbody tr').first().getByRole('button')).toBeVisible();assert(await page.locator('.mo-table tbody tr').first().evaluate(el=>el.scrollWidth<=el.clientWidth+1));await page.screenshot({path:path.join(local,'maintenance-overview-mobile-cards.png')});
 await page.route('**/api/v1/maintenance/overview?*',route=>route.fulfill({status:503,json:{message:'Test'}}));await page.getByRole('button',{name:'↻ Actualiser',exact:true}).click();await expect(page.locator('.maintenance-overview').getByRole('alert')).toContainText('indisponible');await expect(page.locator('.mo-table button').first()).toBeDisabled();
 assert.equal(errors.length,0,errors.join(' | '));console.log('PASS maintenance overview: navigation, plate search, filters, payment/reminder/followup direct dialogs, vehicle identity, empty state, mobile, server error disables stale actions.');
 }finally{await browser.close();}
})().catch(e=>{console.error(String(e).replaceAll(credentials.email,'[email]').replaceAll(credentials.password,'[password]'));process.exit(1)});