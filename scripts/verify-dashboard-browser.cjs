const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {expect}=require((process.env.PLAYWRIGHT_MODULE||'playwright')+'/test');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const local=path.resolve(__dirname,'../.local');
const credentials=JSON.parse(fs.readFileSync(path.join(local,'demo-account.json'),'utf8'));
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const page=await browser.newPage({viewport:{width:1440,height:1050}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  await page.goto('http://localhost:3000/login');await page.getByLabel('Adresse e-mail',{exact:true}).fill(credentials.email);await page.getByLabel('Mot de passe',{exact:true}).fill(credentials.password);await page.getByRole('button',{name:'Se connecter',exact:true}).click();
  await expect(page.locator('.cp-metrics')).toBeVisible();await expect(page.locator('.cp-table-region')).toHaveAttribute('aria-busy','false');
  await page.screenshot({path:path.join(local,'dashboard-live-desktop.png'),fullPage:true});
  console.log('Dashboard loaded',await page.locator('.cp-count').innerText());
  if(process.env.CAPTURE_ONLY){await browser.close();return;}
  async function request(url,method='GET',body){return page.evaluate(async({url,method,body})=>{const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=')||'');const r=await fetch('http://localhost:8000/api/v1/'+url,{method,credentials:'include',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':token},body:body?JSON.stringify(body):undefined});return {status:r.status,data:await r.json()};},{url,method,body});}
  const plate='TEST-DASHBOARD-'+Date.now().toString().slice(-7);
  const vehicle=await request('vehicles','POST',{plate,make:'Dacia',model:'Logan',creation_key:crypto.randomUUID()});assert.equal(vehicle.status,201,JSON.stringify(vehicle.data));const v=vehicle.data.data.id;
  const today=(await request('dashboard/overview')).data.today;
  const loan=await request('loans','POST',{vehicle_id:v,lender:'Banque test dashboard',reference:plate,tracking_from:today,schedule_confirmed:true,opening_confirmed:true,request_key:crypto.randomUUID(),installments:[{due_on:today,amount_centimes:250000,opening_paid_centimes:0}]});assert.equal(loan.status,201,JSON.stringify(loan.data));
  const maintenance=await request(`vehicles/${v}/maintenance/files`,'POST',{mode:'completed',performed_on:today,items:[{kind:'oil',title:'Vidange dashboard',status:'done',cost_status:'confirmed',amount_centimes:65000,completed_on:today}],paid_centimes:20000,paid_on:today,method:'cash',payment_due_on:today,request_key:crypto.randomUUID()});assert.equal(maintenance.status,201,JSON.stringify(maintenance.data));
  await page.locator('#cp-search').fill(plate);await expect(page.locator('.cp-count')).toHaveText('6');
  await page.getByRole('button',{name:'Régler · '+plate+' · Banque test dashboard · Échéance 1',exact:true}).click();
  const file=page.locator('.lc-file-dialog');await expect(file.locator('#loan-payment')).toBeVisible();await file.locator('input[name=amount_centimes]').fill('1000');await expect(file.locator('.lc-preview')).toContainText('1.500,00');await file.locator('#loan-payment input[type=checkbox]').check();await file.getByRole('button',{name:'Confirmer le paiement',exact:true}).click();await expect(file.locator('#loan-payment')).toHaveCount(0);await file.getByRole('button',{name:'Fermer le dossier',exact:true}).click();
  const creditRow=page.locator('tr[data-dashboard-row^="credit-"]');await expect(creditRow).toContainText('1.500,00');assert.equal((await request('loans/'+loan.data.id)).data.installments[0].remaining_centimes,150000);
  await page.getByRole('button',{name:'Régler · '+plate+' · Vidange dashboard',exact:true}).click();
  const garage=page.locator('.gf-dialog');await expect(garage).toBeVisible();
  await garage.locator('.gf-operation input[name=paid]').fill('200');await garage.locator('.gf-operation').getByRole('button',{name:'Enregistrer',exact:true}).click();await expect(garage.locator('.gf-operation')).toHaveCount(0);
  await page.screenshot({path:path.join(local,'dashboard-maintenance-action.png')});
  await page.keyboard.press('Escape');
  await expect(garage).toHaveCount(0);
  await expect(page.locator('tr[data-dashboard-row]').filter({hasText:'Vidange dashboard'})).toContainText('250,00');
  await page.getByRole('button',{name:'Documents · '+plate+' · Assurance',exact:true}).click();await expect(page.locator('.cockpit-document-dialog')).toBeVisible();await expect(page.locator('.cockpit-document-dialog .v-doc-list')).toBeVisible();
  await page.getByRole('button',{name:'Ajouter Visite technique',exact:true}).click();const doc=page.locator('.v-document-dialog');await doc.locator('input[name=effective_on]').fill(today);await doc.locator('input[name=expires_on]').fill('2027-12-01');await doc.locator('input[type=file]').setInputFiles({name:'controle-fictif.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF')});await doc.getByRole('button',{name:'Enregistrer la pièce',exact:true}).click();await expect(doc).toHaveCount(0);await page.getByRole('button',{name:'Fermer les documents',exact:true}).click();await expect(page.locator('.cp-count')).toHaveText('5');
  await page.locator('#cp-horizon').selectOption('7');await expect(page.locator('.cp-table-region')).toHaveAttribute('aria-busy','false');await page.getByRole('button',{name:'Désactiver les animations',exact:true}).click();
  for(const width of [1024,768,390,320]){await page.setViewportSize({width,height:950});await page.screenshot({path:path.join(local,`dashboard-${width}.png`),fullPage:true});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Overflow at '+width);}
  await page.getByRole('button',{name:'Régler · '+plate+' · Banque test dashboard · Échéance 1',exact:true}).click();await expect(file.locator('#loan-payment')).toBeVisible();await file.getByRole('button',{name:'Fermer la saisie',exact:true}).click();await file.getByRole('button',{name:'Fermer le dossier',exact:true}).click();
  await page.getByRole('button',{name:'Ajouter un entretien',exact:true}).click();await expect(page.getByRole('dialog')).toBeVisible();await page.keyboard.press('Escape');await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.setViewportSize({width:1440,height:1050});await page.getByRole('button',{name:'Effacer la recherche',exact:true}).click();await page.locator('#cp-horizon').selectOption('30');await expect(page.locator('.cp-table-region')).toHaveAttribute('aria-busy','false');
  const snapshot=await request('dashboard/overview');assert.equal(snapshot.status,200);await expect(page.locator('[data-total=loans]')).toHaveText(new Intl.NumberFormat('fr-MA',{minimumFractionDigits:2,maximumFractionDigits:2}).format(snapshot.data.summary.loan_remaining_centimes/100)+' DH');
  await page.route('**/api/v1/dashboard/overview?**',r=>r.abort());await page.getByRole('button',{name:'Actualiser',exact:true}).click();await expect(page.locator('.cp-error')).toBeVisible();await expect(page.locator('.cp-row-action').first()).toBeDisabled();await page.unroute('**/api/v1/dashboard/overview?**');await page.getByRole('button',{name:'Réessayer',exact:true}).click();await expect(page.locator('.cp-error')).toHaveCount(0);
  await page.screenshot({path:path.join(local,'dashboard-final.png'),fullPage:true});assert.deepEqual(errors,[]);
  console.log(JSON.stringify({ok:true,plate,vehicle:v,loan:loan.data.id,maintenance:maintenance.data.id,checks:'real balances, partial payment, unchanged URL, maintenance and document dialogs, search/filter, 1024/768/390/320, new maintenance, network failure/retry'}));
 }catch(e){await page.screenshot({path:path.join(local,'dashboard-failure.png'),fullPage:true}).catch(()=>{});console.error(String(e.stack??e).replaceAll(credentials.email,'[redacted]').replaceAll(credentials.password,'[redacted]'));process.exitCode=1;}finally{await browser.close();}
})();
