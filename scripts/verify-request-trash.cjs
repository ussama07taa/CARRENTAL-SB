// Real list/actions components; every API request is mocked. No agency writes.
const assert=require('node:assert/strict');const puppeteer=require('../node_modules/puppeteer');
(async()=>{const b=await puppeteer.launch({headless:true,executablePath:'/home/taaouati/.cache/puppeteer/chrome-headless-shell/linux-155.0.8059.79/chrome-headless-shell-linux64/chrome-headless-shell',args:['--no-sandbox']});try{
 const p=await b.newPage();await p.setViewport({width:1280,height:950});const errors=[];p.on('pageerror',e=>errors.push(e.message));const base='http://localhost:3108';await p.setCookie({name:'XSRF-TOKEN',value:'mock',url:base});
 let trashed=false,cancelled=false,lost=false,mutations=0;const seen=new Set();
 const row=id=>({id,reference:'DEM-00000'+id,source:'agency',status:id===1&&cancelled?'annulee':'demande',created_at:'2026-11-03T10:00:00Z',starts_local:'2026-11-10T10:00',ends_local:'2026-11-12T10:00',duration_minutes:2880,customer:{id:1,first_name:'Client',last_name:'Exemple',phone:'+212600000000'},vehicle:{id:1,make:'Dacia',model:'Jogger',plate:'EXEMPLE',cover_id:null},total_centimes:null});
 await p.setRequestInterception(true);p.on('request',async req=>{const u=new URL(req.url());if(!u.pathname.startsWith('/api/')&&!u.pathname.startsWith('/sanctum/'))return req.continue();const headers={'access-control-allow-origin':base,'access-control-allow-credentials':'true','access-control-allow-methods':'GET, POST, OPTIONS','access-control-allow-headers':'Content-Type, X-XSRF-TOKEN, Accept'};const reply=(data,status=200)=>req.respond({status,contentType:'application/json',headers,body:JSON.stringify(data)});
 if(req.method()==='OPTIONS'||u.pathname.startsWith('/sanctum/'))return reply({});
 if(u.pathname==='/api/v1/reservations'){
  const trash=u.searchParams.get('trash')==='1';let data=trash?(trashed?[row(1)]:[]):[...(!trashed?[row(1)]:[]),row(2)];const counts={demande:data.filter(r=>r.status==='demande').length,annulee:data.filter(r=>r.status==='annulee').length};if(u.searchParams.has('status'))data=data.filter(r=>r.status===u.searchParams.get('status'));
  return reply({data,total:data.length,page:1,last_page:1,matching_total:trash?(trashed?1:0):(trashed?1:2),counts,vehicles:[],can_manage_requests:true,trash});
 }
 if(u.pathname.endsWith('/disposal')){
  if(req.method()==='GET'){const locked=u.pathname.includes('/2/');return reply({can_trash:!locked&&!trashed,can_cancel_quick:!locked&&!trashed,can_restore:!locked&&trashed,reason:locked?'Ce dossier contient un paiement. Utilisez son règlement.':null});}
  const input=JSON.parse(req.postData());assert.ok(input.reason.length>=3);if(!seen.has(input.request_key)){mutations++;seen.add(input.request_key);if(input.action==='trash')trashed=true;if(input.action==='restore')trashed=false;if(input.action==='cancel_quick')cancelled=true;}
  if(input.action==='trash'&&!lost){lost=true;return req.abort('failed');}return reply({replayed:true});
 }
 return reply({message:'Unmocked endpoint'},503);
 });
 const clickText=async(selector,text)=>{await p.waitForFunction((s,t)=>[...document.querySelectorAll(s)].some(e=>e.textContent.trim()===t),{},selector,text);await p.evaluate((s,t)=>[...document.querySelectorAll(s)].find(e=>e.textContent.trim()===t).click(),selector,text);};
 await p.goto(base+'/qa-request-trash',{waitUntil:'networkidle0'});await p.waitForSelector('[aria-label="Actions DEM-000001"]');
 await p.click('[aria-label="Actions DEM-000002"]');await p.waitForFunction(()=>document.querySelector('.request-action-choices .danger')?.disabled===true);await p.click('[aria-label="Fermer les actions"]');
 await p.click('[aria-label="Actions DEM-000001"]');await clickText('.request-action-choices button','Supprimer · vers la corbeille');
 await p.click('.request-actions-dialog button[type=submit]');assert.equal(await p.$eval('#request-action-reason',e=>e===document.activeElement),true);assert.equal(mutations,0);
 await p.type('#request-action-reason','Doublon de saisie');await p.click('.request-actions-dialog button[type=submit]');await clickText('.request-actions-dialog button[type=submit]','Réessayer');await p.waitForSelector('.request-actions-dialog',{hidden:true});assert.equal(mutations,1);
 await clickText('.rq-status-tabs button','Corbeille');await p.waitForSelector('[aria-label="Actions DEM-000001"]');assert.equal(await p.$$('.rq-directory-table tbody tr').then(a=>a.length),1);
 await p.click('[aria-label="Actions DEM-000001"]');await clickText('.request-action-choices button','Restaurer la demande');
 for(const width of [320,768,1280]){await p.setViewport({width,height:900});assert.equal(await p.$eval('.request-actions-dialog',e=>e.scrollWidth<=e.clientWidth),true);}
 await p.screenshot({path:'/tmp/request-trash-restore.png'});await p.click('.request-actions-dialog button[type=submit]');await p.waitForSelector('.request-actions-dialog',{hidden:true});await p.waitForFunction(()=>document.body.innerText.includes('La corbeille est vide.'));assert.equal(mutations,2);
 await clickText('.rq-empty button','Retour aux demandes');await p.waitForSelector('[aria-label="Actions DEM-000001"]');await p.click('[aria-label="Actions DEM-000001"]');await clickText('.request-action-choices button','Annuler la demande');await p.type('#request-action-reason','Client désisté');await p.click('.request-actions-dialog button[type=submit]');await p.waitForSelector('.request-actions-dialog',{hidden:true});await p.waitForSelector('[aria-label="Actions DEM-000001"]',{hidden:true});assert.equal(mutations,3);assert.deepEqual(errors,[]);
 console.log('PASS: protected dossier, validation focus, lost-response retry (one action), trash, restore, cancellation, 320/768/1280; all APIs mocked.');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exit(1)});
