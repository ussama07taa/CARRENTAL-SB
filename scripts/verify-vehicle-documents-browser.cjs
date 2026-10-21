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
 await page.getByLabel('Rechercher un véhicule', {exact:true}).fill('TEST-DOCS-01');
 const open=page.getByRole('button',{name:'Ouvrir TEST-DOCS-01',exact:true});
 await expect(open.or(page.getByRole('heading',{name:'Aucun véhicule trouvé.'}))).toBeVisible();
 if(!(await open.isVisible())){
 await page.getByRole('button',{name:'Ajouter un véhicule',exact:true}).click();
 await page.locator('input[name=plate]').fill('TEST-DOCS-01');
 await page.locator('select[name=make_choice]').selectOption('Dacia');await page.locator('select[name=model_choice]').selectOption('Logan');
 await page.getByRole('button',{name:'Créer le fichier',exact:true}).click();await expect(page.locator('.vehicle-summary')).toBeVisible();await page.getByRole('button',{name:'Fermer',exact:true}).click();
 }
 await open.click();await expect(page.locator('.v-doc-row')).toHaveCount(4);
 const dates=await page.evaluate(()=>{const base=new Date();const fmt=d=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Casablanca',year:'numeric',month:'2-digit',day:'2-digit'}).format(d);const add=n=>{const d=new Date(base);d.setDate(d.getDate()+n);return fmt(d)};return{today:add(0),past:add(-30),soon:add(30),urgent:add(7),year:add(365),yearNumber:base.getFullYear()};});
 const png=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=800;c.height=480;const x=c.getContext('2d');x.fillStyle='#e6edf0';x.fillRect(0,0,800,480);x.fillStyle='#213440';x.fillRect(0,0,800,110);x.fillStyle='white';x.font='bold 28px Arial';x.fillText('JUSTIFICATIF FICTIF',35,65);x.fillStyle='#475f6f';x.font='24px Arial';x.fillText('TEST-DOCS-01',35,185);x.fillText('Sans valeur officielle',35,240);return c.toDataURL('image/png').split(',')[1]});
 const image={name:'document-fictif.png',mimeType:'image/png',buffer:Buffer.from(png,'base64')};
 // Valid single-page PDF generated only for test data.
 const nl=String.fromCharCode(10),stream='BT /F1 18 Tf 40 740 Td (TEST DOCUMENT - NO LEGAL VALUE) Tj ET';
 const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '+stream.length+' >>'+nl+'stream'+nl+stream+nl+'endstream'];
 let pdf='%PDF-1.4'+nl,offsets=[0];for(let i=0;i<objects.length;i++){offsets.push(Buffer.byteLength(pdf));pdf+=(i+1)+' 0 obj'+nl+objects[i]+nl+'endobj'+nl;}const start=Buffer.byteLength(pdf);pdf+='xref'+nl+'0 6'+nl+'0000000000 65535 f '+nl+offsets.slice(1).map(x=>String(x).padStart(10,'0')+' 00000 n '+nl).join('')+'trailer'+nl+'<< /Size 6 /Root 1 0 R >>'+nl+'startxref'+nl+start+nl+'%%EOF';
 const pdfFile={name:'assurance-fictive.pdf',mimeType:'application/pdf',buffer:Buffer.from(pdf)};
 const editor=page.locator('.v-document-dialog');
 async function editDoc(label){const add=page.getByRole('button',{name:'Ajouter '+label,exact:true});if(await add.isVisible()){await add.click();return false;}await page.getByRole('button',{name:(label==='Carte grise'?'Mettre à jour ':'Corriger ')+label,exact:true}).click();await editor.locator('textarea[name=reason]').fill('Données fictives pour vérification');return true;}
 async function saveDoc(){await editor.getByRole('button',{name:'Enregistrer la pièce',exact:true}).click();await expect(editor).toHaveCount(0,{timeout:15000});await expect(page.locator('.vehicle-summary')).toBeVisible();}
 await editDoc('Carte grise');await editor.getByLabel('Fichier justificatif', {exact:true}).setInputFiles(image);await saveDoc();
 await editDoc('Assurance');await editor.locator('input[name=provider]').fill('Assureur Démonstration');await editor.locator('input[name=policy_number]').fill('DEMO-CONTRAT-001');await editor.locator('input[name=effective_on]').fill(dates.past);await editor.locator('input[name=expires_on]').fill(dates.urgent);await editor.getByLabel('Fichier justificatif', {exact:true}).setInputFiles(pdfFile);
 await page.screenshot({path:path.join(local,'vehicle-document-editor.png')});
 await page.route('**/api/v1/vehicles/*/documents',route=>route.request().method()==='POST'?route.abort('failed'):route.continue());
 await editor.getByRole('button',{name:'Enregistrer la pièce',exact:true}).click();await expect(editor.locator('.v-error-box')).toBeVisible();await expect(editor.getByText('assurance-fictive.pdf',{exact:true})).toBeVisible();await page.unroute('**/api/v1/vehicles/*/documents');await saveDoc();
 const insurance=page.locator('.v-doc-row').filter({has:page.locator('.v-doc-name strong',{hasText:/^Assurance$/})});
 await expect(insurance.locator('.v-deadline')).toHaveText('Dans 7 jours');
 await editDoc('Visite technique');await editor.locator('input[name=effective_on]').fill(dates.today);await editor.locator('input[name=expires_on]').fill(dates.soon);await saveDoc();
 const inspection=page.locator('.v-doc-row').filter({has:page.locator('.v-doc-name strong',{hasText:/^Visite technique$/})});
 await expect(inspection.locator('.v-deadline')).toHaveText('Dans 30 jours');await expect(inspection.getByText('Justificatif non joint',{exact:true})).toBeVisible();
 await editDoc('Vignette');await editor.locator('input[name=tax_year]').fill(String(dates.yearNumber));await editor.locator('input[name=paid_on]').fill(dates.today);await editor.locator('input[name=expires_on]').fill(dates.year);await editor.getByLabel('Fichier justificatif', {exact:true}).setInputFiles(image);await saveDoc();
 await page.locator('.v-documents').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(local,'vehicle-documents-desktop.png')});
 const downloaded=page.waitForEvent('download');await page.getByRole('button',{name:'Télécharger Assurance',exact:true}).click();const download=await downloaded;assert.equal(await download.failure(),null);assert(download.suggestedFilename().endsWith('.pdf'));
 await page.getByRole('button',{name:'Renouveler Assurance',exact:true}).click();await editor.locator('input[name=effective_on]').fill(dates.today);await editor.locator('input[name=expires_on]').fill(dates.year);await editor.getByLabel('Fichier justificatif', {exact:true}).setInputFiles(pdfFile);await saveDoc();
 await expect(insurance.locator('.v-deadline')).toHaveText('À jour');
 await page.getByRole('button',{name:'Historique Assurance',exact:true}).click();await expect(insurance.locator('.v-doc-history')).toContainText('Renouvellement');await expect(insurance.locator('.v-doc-history')).toContainText('DEMO-CONTRAT-001');
 const oldDownload=page.waitForEvent('download');await insurance.locator('.v-doc-history').getByRole('button',{name:/Télécharger Assurance version/}).last().click();assert.equal(await (await oldDownload).failure(),null);
 await page.getByRole('button',{name:'Fermer',exact:true}).click();await open.click();await expect(page.locator('.v-doc-row')).toHaveCount(4);await expect(insurance.locator('.v-deadline')).toHaveText('À jour');
 await page.setViewportSize({width:390,height:844});await page.locator('.v-documents').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(local,'vehicle-documents-mobile.png')});assert(await page.locator('.vehicle-dialog').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
 await editDoc('Assurance');await editor.locator('input[name=policy_number]').fill('Changement à annuler');page.once('dialog',d=>d.dismiss());await editor.getByRole('button',{name:'Annuler',exact:true}).click();await expect(editor).toBeVisible();page.once('dialog',d=>d.accept());await editor.getByRole('button',{name:'Annuler',exact:true}).click();await expect(editor).toHaveCount(0);await expect(page.locator('.vehicle-summary')).toBeVisible();
 assert.equal(errors.length,0,errors.join(' | '));
 console.log('PASS: 4 document types, private PNG/PDF uploads/downloads, 7/30-day alerts, missing file distinction, renewal and old downloads, failed upload retry, reopen, mobile, unsaved cancel.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});