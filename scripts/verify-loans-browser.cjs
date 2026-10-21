const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {expect}=require((process.env.PLAYWRIGHT_MODULE||'playwright')+'/test');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const local=path.resolve(__dirname,'../.local');
const credentials=JSON.parse(fs.readFileSync(path.join(local,'demo-account.json'),'utf8'));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});let page;
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}});
  page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://localhost:3000/login');
  await page.getByLabel('Adresse e-mail',{exact:true}).fill(credentials.email);
  await page.getByLabel('Mot de passe',{exact:true}).fill(credentials.password);
  await page.getByRole('button',{name:'Se connecter',exact:true}).click();
  await expect(page).toHaveURL('http://localhost:3000/');
  await page.getByRole('link',{name:'Véhicules',exact:true}).click();
  const plate='TEST-CREDIT-SIMPLE-'+Date.now().toString().slice(-7);
  await page.getByRole('button',{name:'Ajouter un véhicule',exact:true}).click();
  await page.locator('input[name=plate]').fill(plate);
  await page.locator('select[name=make_choice]').selectOption('Dacia');
  await page.locator('select[name=model_choice]').selectOption('Logan');
  await page.getByRole('button',{name:'Créer le fichier',exact:true}).click();
  await page.getByRole('button',{name:'Fermer',exact:true}).click();
  await page.getByRole('link',{name:'Crédits & échéances',exact:true}).click();
  const dialog=page.locator('.lc-dialog'),file=page.locator('.lc-file-dialog');
  const input=name=>dialog.locator(`input[name="${name}"]`);
  const button=name=>dialog.getByRole('button',{name,exact:true});
  const read=async id=>page.evaluate(async id=>(await fetch('http://localhost:8000/api/v1/loans/'+id,{credentials:'include',headers:{Accept:'application/json'}})).json(),id);
  async function start(reference,existing){
   await expect(page.getByRole('button',{name:'＋ Ajouter un crédit',exact:true})).toBeEnabled();
   await page.getByRole('button',{name:'＋ Ajouter un crédit',exact:true}).click();
   if(existing)await dialog.getByRole('button',{name:/Crédit déjà en cours/}).click();
   await dialog.locator('#loan-vehicle-search').fill(plate);
   await button('Choisir '+plate).click();
   await input('lender').fill('Banque de démonstration');await input('reference').fill(reference);
  }
  async function save(){
   await dialog.locator('input[data-loan-field=schedule_confirmed]').check();
   const old=dialog.locator('input[data-loan-field=opening_confirmed]');if(await old.count())await old.check();
   let calls=0;const listener=r=>{if(r.method()==='POST'&&r.url().endsWith('/api/v1/loans'))calls++;};page.on('request',listener);
   const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().endsWith('/api/v1/loans'));
   await button('Enregistrer le contrat').evaluate(b=>{b.click();b.click();});
   const r=await response;assert.equal(r.status(),201,await r.text());const id=(await r.json()).id;assert.equal(calls,1);page.off('request',listener);
   await expect(file.locator('.lc-next-due')).toBeVisible();return id;
  }
  async function edit(index,fields){
   await button('Modifier échéance '+index).click();
   for(const [key,value] of Object.entries(fields))await input(`installments.${index-1}.${key}`).fill(value);
   await button('Valider cette ligne').click();
   await expect(dialog.locator('.lc-edit-one')).toHaveCount(0);
  }
  // Existing contract: 9 full installments and a partial tenth, never new cash.
  await start('OLD-'+plate,true);
  await page.screenshot({path:path.join(local,'loans-simple-choice.png')});
  await dialog.getByText('Dates, copie du contrat et informations complémentaires',{exact:true}).click();
  await input('tracking_from').fill('2026-10-17');
  await input('file').setInputFiles({name:'contrat-fictif.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF')});
  await button('Continuer →').click();
  await input('generator_amount').fill('3000');await input('generator_count').fill('12');await input('generator_first').fill('2026-01-05');
  await input('past').fill('13');await input('loan_partial_opening').fill('3100');await button('Afficher le tableau').click();
  await expect(input('past')).toHaveAttribute('aria-invalid','true');await expect(input('loan_partial_opening')).toHaveAttribute('aria-invalid','true');
  await input('past').fill('9');await input('loan_partial_opening').fill('1000');await expect(input('past')).toHaveAttribute('aria-invalid','false');await expect(input('loan_partial_opening')).toHaveAttribute('aria-invalid','false');
  await page.screenshot({path:path.join(local,'loans-simple-existing-entry.png')});
  await button('Afficher le tableau').click();await expect(dialog.locator('.lc-schedule-table tbody tr:not(.lc-row-editor)')).toHaveCount(12);
  await expect(dialog.locator('.lc-schedule-table input')).toHaveCount(0);
  await expect(dialog.locator('#loan-prepared-table')).toContainText('8.000,00');
  await edit(12,{amount_centimes:'3500.50'});
  await expect(dialog.locator('#loan-prepared-table')).toContainText('8.500,50');
  await dialog.locator('#loan-prepared-table').scrollIntoViewIfNeeded();
  await page.screenshot({path:path.join(local,'loans-simple-schedule.png')});
  await button('Continuer →').click();await button('Enregistrer le contrat').click();
  await expect(dialog.locator('input[data-loan-field=schedule_confirmed]')).toHaveAttribute('aria-invalid','true');
  const oldId=await save();let d=await read(oldId);
  assert.equal(d.data.total_centimes,3650050);assert.equal(d.data.opening_paid_centimes,2800000);assert.equal(d.data.recorded_paid_centimes,0);assert.equal(d.data.remaining_centimes,850050);assert.equal(d.payments.length,0);
  const hero=file.locator('.lc-next-due');await expect(hero).toContainText('N° 10');await expect(hero).toContainText('EN RETARD');await expect(hero).toContainText('2.000,00');
  const heroTop=await hero.boundingBox(),metricsTop=await file.locator('.lc-file-metrics').boundingBox();assert.ok(heroTop.y<metricsTop.y);
  await file.locator('.lc-dialog-body').evaluate(e=>e.scrollTop=0);await page.screenshot({path:path.join(local,'loans-simple-next-desktop.png')});
  await hero.getByRole('button',{name:'Enregistrer un paiement',exact:true}).click();
  const payment=file.locator('#loan-payment');await expect(payment).toBeVisible();await input('amount_centimes').fill('3000');await button('Confirmer le paiement').click();await expect(input('amount_centimes')).toHaveAttribute('aria-invalid','true');
  await input('amount_centimes').fill('500');await payment.getByRole('checkbox').check();
  let payments=0;page.on('request',r=>{if(r.method()==='POST'&&/\/installments\/\d+\/payments$/.test(r.url()))payments++;});
  await button('Confirmer le paiement').evaluate(b=>{b.click();b.click();});await expect(payment).toHaveCount(0);assert.equal(payments,1);
  await expect(hero).toContainText('1.500,00');await hero.getByRole('button',{name:'Enregistrer un paiement',exact:true}).click();await expect(input('amount_centimes')).toHaveValue('1500');
  await payment.getByRole('checkbox').check();await button('Confirmer le paiement').click();await expect(payment).toHaveCount(0);await expect(hero).toContainText('N° 11');
  await expect(hero).not.toContainText('EN RETARD');d=await read(oldId);assert.equal(d.data.remaining_centimes,650050);assert.equal(d.data.recorded_paid_centimes,200000);assert.equal(d.payments.length,2);assert.equal(d.data.opening_paid_centimes,2800000);
  const download=page.waitForEvent('download');await file.locator('.lc-document').click();assert.ok((await download).suggestedFilename().endsWith('.pdf'));
  await page.setViewportSize({width:390,height:844});await file.locator('.lc-dialog-body').evaluate(e=>e.scrollTop=0);await expect(hero.getByRole('button')).toBeInViewport();await page.screenshot({path:path.join(local,'loans-simple-next-mobile.png')});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await hero.getByRole('button').click();await expect(payment).toBeVisible();await button('Fermer la saisie').click();await button('Fermer le dossier').click();
  await page.setViewportSize({width:1440,height:1000});
  // New credit has no history fields. Long schedules paginate and only one row opens.
  await start('NEW-'+plate,false);await button('Continuer →').click();await expect(input('past')).toHaveCount(0);
  await input('generator_amount').fill('3000');await input('generator_count').fill('24');await input('generator_first').fill('2026-01-31');await button('Afficher le tableau').click();
  await expect(dialog.locator('.lc-schedule-table')).toContainText('28 fév. 2026');await expect(dialog.locator('.lc-schedule-table')).toContainText('31 mar. 2026');await expect(dialog.locator('.lc-schedule-table input')).toHaveCount(0);
  await button('Suivantes').click();await button('Modifier échéance 13').click();await input('installments.12.amount_centimes').fill('0');await button('Continuer →').click();await expect(input('installments.12.amount_centimes')).toHaveAttribute('aria-invalid','true');
  await input('installments.12.amount_centimes').fill('3500.50');await button('Valider cette ligne').click();
  await input('generator_count').fill('1');await button('Continuer →').click();await expect(dialog.getByText('Les paramètres ont changé. Actualisez le tableau avant de continuer.',{exact:true})).toBeVisible();
  await input('generator_first').fill('2026-11-05');page.once('dialog',d=>d.accept());await button('Actualiser le tableau').click();await expect(dialog.locator('.lc-schedule-table tbody tr')).toHaveCount(1);
  // Changing mode cannot carry invisible historical amounts into a new credit.
  await button('Retour').click();await dialog.getByRole('button',{name:/Crédit déjà en cours/}).click();await button('Continuer →').click();await input('past').fill('0');await input('loan_partial_opening').fill('1000');await button('Actualiser le tableau').click();await expect(dialog.locator('#loan-prepared-table')).toContainText('2.000,00');
  await button('Retour').click();page.once('dialog',d=>d.accept());await dialog.getByRole('button',{name:/Nouveau crédit/}).click();await button('Continuer →').click();await expect(dialog.locator('#loan-prepared-table')).toContainText('3.000,00');await expect(dialog.locator('.lc-schedule-table')).not.toContainText('Déjà payé');
  await page.setViewportSize({width:390,height:844});await dialog.locator('#loan-prepared-table').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(local,'loans-simple-schedule-mobile.png')});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await button('Continuer →').click();const newId=await save();d=await read(newId);assert.equal(d.data.opening_paid_centimes,0);assert.equal(d.data.remaining_centimes,300000);
  await hero.getByRole('button').click();await input('amount_centimes').fill('3000');await payment.getByRole('checkbox').check();await button('Confirmer le paiement').click();await expect(hero).toContainText('Aucun versement restant');await expect(hero.getByRole('button')).toHaveCount(0);d=await read(newId);assert.equal(d.data.remaining_centimes,0);assert.equal(d.payments.length,1);
  await button('Fermer le dossier').click();await page.setViewportSize({width:1440,height:1000});
  await page.locator('#loan-search').fill(plate);await expect(page.locator('.lc-loan-list tbody tr')).toHaveCount(2);await page.locator('.lc-loan-list tbody tr').filter({hasText:'OLD-'+plate}).getByRole('button').click();await expect(hero).toContainText('N° 11');assert.deepEqual(errors,[]);
  console.log(JSON.stringify({ok:true,plate,oldId,newId,totalDH:36500.50,historicalDH:28000,newPaymentsDH:2000,remainingDH:6500.50,checks:'new/existing, bulk partial history, one-row editing, pagination, month ends, stale preparation, mode reset, partial/full payment, settled state, download, mobile'}));
 }catch(e){if(page)await page.screenshot({path:path.join(local,'loans-simple-failure.png')}).catch(()=>{});console.error(String(e.stack??e.message).replaceAll(credentials.email,'[redacted]').replaceAll(credentials.password,'[redacted]'));process.exitCode=1;}finally{await browser.close();}
})();
