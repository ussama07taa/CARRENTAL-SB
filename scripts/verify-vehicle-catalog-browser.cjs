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
 await expect(page.getByRole('button',{name:'Ouvrir DEMO-1',exact:true})).toBeVisible();
 await page.screenshot({path:path.join(local,'vehicles-desktop.png'),fullPage:true});
 await page.getByRole('button',{name:'Ajouter un véhicule',exact:true}).click();
 const brand=page.locator('select[name=make_choice]'), model=page.locator('select[name=model_choice]');
 await expect(model).toBeDisabled();
 await page.locator('input[name=plate]').fill('TEST-CATALOG-01');
 await page.getByRole('tab',{name:/Photos/}).click();
 await page.getByRole('button',{name:'Créer le fichier',exact:true}).click();
 await expect(brand).toBeFocused();
 await brand.selectOption('Dacia');
 await expect(model).toBeEnabled();
 await model.selectOption('Logan');
 await brand.selectOption('Renault');
 await expect(model).toHaveValue('');
 await expect(model.locator('option')).not.toContainText(['Logan']);
 await model.selectOption('Clio');
 await page.screenshot({path:path.join(local,'vehicle-catalog-desktop.png')});
 page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Fermer le fichier',exact:true}).click();
 await page.getByLabel('Rechercher un véhicule', {exact:true}).fill('TEST-CATALOG-01');
 const open=page.getByRole('button',{name:'Ouvrir TEST-CATALOG-01',exact:true});
 await expect(open.or(page.getByRole('heading',{name:'Aucun véhicule trouvé.'}))).toBeVisible();
 if(await open.isVisible()){await open.click();await page.getByRole('button',{name:'Modifier le fichier',exact:false}).click();}else{
 await page.getByRole('button',{name:'Ajouter un véhicule',exact:true}).click();
 await page.locator('input[name=plate]').fill('TEST-CATALOG-01');
 }
 await brand.selectOption('Dacia');await model.selectOption('Logan');
 await page.getByRole('button',{name:/^(Créer le fichier|Enregistrer les modifications)$/}).click();
 await expect(page.locator('.vehicle-dialog')).not.toBeVisible();
 await open.click();await page.getByRole('button',{name:'Modifier le fichier',exact:false}).click();await expect(brand).toHaveValue('Dacia');await expect(model).toHaveValue('Logan');
 // Unknown values are preserved exactly; changing make clears only the model.
 await brand.selectOption('__other__');await page.locator('input[name=make]').fill('Marque Démo');
 await page.locator('input[name=model]').fill('Modèle Démo');
 await page.getByRole('button',{name:'Enregistrer les modifications',exact:true}).click();
 await expect(page.locator('.vehicle-dialog')).not.toBeVisible();
 await open.click();await page.getByRole('button',{name:'Modifier le fichier',exact:false}).click();await expect(page.locator('input[name=make]')).toHaveValue('Marque Démo');
 await expect(page.locator('input[name=model]')).toHaveValue('Modèle Démo');
 await brand.selectOption('Renault');await expect(model).toHaveValue('');
 await model.selectOption('Clio');
 await page.setViewportSize({width:390,height:844});
 await page.screenshot({path:path.join(local,'vehicle-catalog-mobile.png')});
 assert(await page.locator('.vehicle-dialog').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
 await page.getByRole('button',{name:'Enregistrer les modifications',exact:true}).click();
 await expect(page.locator('.vehicle-dialog')).not.toBeVisible();
 await open.click();await page.getByRole('button',{name:'Modifier le fichier',exact:false}).click();await expect(brand).toHaveValue('Renault');await expect(model).toHaveValue('Clio');
 assert.equal(errors.length,0,errors.join('\n'));
 console.log('PASS: dependent make/model choices, reset on make change, required-field validation across tabs, persisted selections, unknown existing values and mobile layout.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});