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




 await page.getByLabel('Rechercher un véhicule',{exact:true}).fill('TEST-GARAGE-7426844');await page.getByRole('button',{name:'Ouvrir TEST-GARAGE-7426844',exact:true}).click();await page.locator('.v-maintenance').getByRole('button',{name:'Enregistrer une intervention'}).click();
 const form=page.locator('.gf-dialog');await form.locator('[name=garage]').fill('Garage test erreurs');await form.locator('[name=kilometers]').fill('46.000');await form.locator('[name=amount]').fill('650');
 await form.getByRole('button',{name:'Créer le dossier',exact:true}).click();await expect(form.locator('[name=kilometers]')).toHaveAttribute('aria-invalid','true');await expect(form.locator('[name=kilometers]')).toBeFocused();await expect(form.locator('#gf-error-kilometers')).toContainText('46000');await expect(form.locator('[name=garage]')).toHaveValue('Garage test erreurs');
 await page.screenshot({path:path.join(local,'maintenance-field-error.png')});
 await form.locator('[name=kilometers]').fill('46000');await expect(form.locator('[name=kilometers]')).toHaveAttribute('aria-invalid','false');
 await form.getByRole('button',{name:'Ajouter un travail',exact:false}).click();const lines=form.locator('.gf-work-card');await lines.nth(1).locator('[name=title]').fill('Freins');await lines.nth(1).locator('[name=amount]').fill('abc');await form.getByRole('button',{name:'Créer le dossier',exact:true}).click();await expect(lines.nth(1).locator('[name=amount]')).toBeFocused();await expect(lines.first().locator('[name=amount]')).toHaveAttribute('aria-invalid','false');await expect(lines.nth(1).locator('.gf-field-error')).toContainText('650,50');
 await lines.nth(1).locator('[name=amount]').fill('150');await page.route('**/maintenance/files',route=>route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{'items.1.next_on':['La prochaine date est incorrecte.']}})}));await form.getByRole('button',{name:'Créer le dossier',exact:true}).click();await expect(lines.nth(1).locator('[name=next_on]')).toBeFocused();await expect(lines.nth(1).locator('.gf-next')).toHaveAttribute('open','');await expect(lines.nth(1).locator('.gf-field-error')).toContainText('prochaine date');await expect(form.locator('[name=garage]')).toHaveValue('Garage test erreurs');await page.unroute('**/maintenance/files');
 await page.setViewportSize({width:390,height:844});await form.locator('[name=kilometers]').fill('46.5');await form.getByRole('button',{name:'Créer le dossier',exact:true}).click();await expect(form.locator('[name=kilometers]')).toBeFocused();assert(await form.evaluate(el=>el.scrollWidth<=el.clientWidth+1));await page.screenshot({path:path.join(local,'maintenance-field-error-mobile.png')});
 assert.equal(errors.length,0,errors.join(' | '));console.log('PASS field errors: integer guidance, inline highlight, focus, second work item, server 422, hidden details opened, preserved input, mobile. No records created.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
