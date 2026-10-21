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
 await page.getByRole('button',{name:'Se connecter',exact:true}).click();await expect(page.locator('#email')).toBeFocused();await expect(page.locator('#email')).toHaveAttribute('aria-invalid','true');await expect(page.locator('#login-password-error')).toContainText('Renseignez');await page.locator('#email').fill('incorrect');await page.getByRole('button',{name:'Se connecter',exact:true}).click();await expect(page.locator('#login-email-error')).toContainText('e-mail valide');
 await page.getByLabel('Adresse e-mail',{exact:true}).fill(credentials.email);
 await page.getByLabel('Mot de passe',{exact:true}).fill(credentials.password);
 await page.getByRole('button',{name:'Se connecter',exact:true}).click();
 await expect(page).toHaveURL('http://localhost:3000/');
 await page.getByRole('link',{name:'Véhicules',exact:true}).click();
 await expect(page.getByRole('button',{name:'Ajouter un véhicule',exact:true})).toBeVisible();




 await page.getByRole('link',{name:'Clients',exact:true}).click();await page.getByRole('button',{name:'Nouveau client',exact:true}).click();await page.getByRole('button',{name:'Créer le client',exact:true}).click();await expect(page.locator('#client-first_name')).toBeFocused();await expect(page.locator('#client-phone')).toHaveAttribute('aria-invalid','true');
 await page.locator('#client-first_name').fill('Test');await page.locator('#client-last_name').fill('Erreurs');await page.locator('#client-phone').fill('0600000022');
 await page.route('**/api/v1/customers',route=>route.request().method()==='POST'?route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{licence_number:['Numéro de permis invalide.']}})}):route.continue());
 await page.getByRole('button',{name:'Créer le client',exact:true}).click();await expect(page.locator('#client-licence_number')).toBeFocused();await expect(page.locator('#help-licence_number')).toContainText('permis invalide');await expect(page.locator('#client-first_name')).toHaveValue('Test');await page.unroute('**/api/v1/customers');
 page.on('dialog',d=>d.accept());await page.goto('http://localhost:3000/vehicles');await page.getByRole('button',{name:'Ajouter un véhicule',exact:true}).click();await page.getByRole('button',{name:'Créer le fichier',exact:true}).click();await expect(page.locator('[name=plate]')).toBeFocused();await expect(page.locator('[name=make_choice]')).toHaveAttribute('aria-invalid','true');
 await page.locator('[name=plate]').fill('TEST-ERRORS');await page.locator('[name=make_choice]').selectOption('Dacia');await page.locator('[name=model_choice]').selectOption('Logan');
 await page.route('**/api/v1/vehicles',route=>route.request().method()==='POST'?route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{initial_odometer:['Le compteur est inférieur au dernier relevé.']}})}):route.continue());await page.getByRole('button',{name:'Créer le fichier',exact:true}).click();await expect(page.locator('[name=kilometers]')).toBeFocused();await expect(page.locator('#v-error-kilometers')).toContainText('inférieur');await expect(page.locator('[name=plate]')).toHaveValue('TEST-ERRORS');await page.unroute('**/api/v1/vehicles');
 await page.goto('http://localhost:3000/vehicles');await page.getByLabel('Rechercher un véhicule',{exact:true}).fill('TEST-GARAGE-7426844');await page.getByRole('button',{name:'Ouvrir TEST-GARAGE-7426844',exact:true}).click();await page.getByRole('button',{name:'Ajouter Assurance',exact:true}).click();const doc=page.locator('.v-document-dialog');await doc.getByRole('button',{name:'Enregistrer la pièce',exact:true}).click();await expect(doc.locator('[name=provider]')).toBeFocused();await expect(doc.locator('[name=expires_on]')).toHaveAttribute('aria-invalid','true');
 await doc.locator('[name=provider]').fill('Assureur fictif');await doc.locator('[name=effective_on]').fill('2026-10-17');await doc.locator('[name=expires_on]').fill('2027-10-17');await page.route('**/vehicles/*/documents',route=>route.request().method()==='POST'?route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{expires_on:['La date de fin doit suivre la date de début.']}})}):route.continue());await doc.getByRole('button',{name:'Enregistrer la pièce',exact:true}).click();await expect(doc.locator('[name=expires_on]')).toBeFocused();await expect(doc.locator('#doc-error-expires_on')).toContainText('date de début');await page.unroute('**/vehicles/*/documents');
 await doc.locator('[name=file]').setInputFiles({name:'bad.txt',mimeType:'text/plain',buffer:Buffer.from('fake')});await expect(doc.locator('.field-upload-invalid')).toBeVisible();await expect(doc.locator('#doc-error-file')).toContainText('PDF');
 await page.setViewportSize({width:390,height:844});await doc.getByRole('button',{name:'Enregistrer la pièce',exact:true}).click({trial:true});assert(await doc.evaluate(el=>el.scrollWidth<=el.clientWidth+1));await page.screenshot({path:path.join(local,'all-form-errors-mobile.png')});
 assert.equal(errors.length,0,errors.join(' | '));console.log('PASS all forms: login required/email, customer required and hidden licence server error, vehicle required and odometer tab error, document required/dates/file, preserved values, focus, mobile. No records created.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
