// Browser regression against an isolated route rendering Vehicles. Every API call is mocked.
const assert = require('node:assert/strict');
const puppeteer = require('../node_modules/puppeteer');
const base = process.env.PREVIEW_URL || 'http://127.0.0.1:3103';
const kinds = ['registration','insurance','inspection','tax','circulation'];
const emptyGroups = () => Object.fromEntries(kinds.map(k=>[k,{latest:null,current:null,history:[],total:0}]));
(async()=>{
 const browser=await puppeteer.launch({headless:true,executablePath:process.env.CHROME_BIN||'/home/taaouati/.cache/puppeteer/chrome-headless-shell/linux-155.0.8059.79/chrome-headless-shell-linux64/chrome-headless-shell',args:['--no-sandbox']});
 try {
  for(const scenario of ['skip','upload-retry']){
   const page=await browser.newPage();await page.setViewport({width:1280,height:950});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   let vehicle=null,creates=0,attempts=0,lostResponse=false;const groups=emptyGroups(),keys=new Map();
   await page.setCookie({name:'XSRF-TOKEN',value:'mock',url:base});
   await page.setRequestInterception(true);
   page.on('request',async req=>{
    const u=new URL(req.url());
    if(!u.pathname.startsWith('/api/')&&!u.pathname.startsWith('/sanctum/'))return req.continue();
    const headers={'access-control-allow-origin':base,'access-control-allow-credentials':'true','access-control-allow-methods':'GET, POST, PUT, OPTIONS','access-control-allow-headers':'Content-Type, X-XSRF-TOKEN, Accept'};
    const reply=(data,status=200)=>req.respond({status,contentType:'application/json',headers,body:JSON.stringify(data)});
    if(req.method()==='OPTIONS'||u.pathname.startsWith('/sanctum/'))return reply({});
    if(u.pathname.endsWith('/equipment-catalog'))return reply({data:[]});
    if(u.pathname==='/api/v1/vehicles'&&req.method()==='GET')return reply({data:vehicle?[vehicle]:[],can_manage_pricing:false,pricing_ready:false,total:vehicle?1:0,fleet_total:vehicle?1:0,page:1,last_page:1,makes:[]});
    if(u.pathname==='/api/v1/vehicles'&&req.method()==='POST'){
     creates++;vehicle={...JSON.parse(req.postData()),id:900001,reference:'VH-QA',version:1,year:null,seats:null,doors:null,current_odometer:null,current_odometer_date:null,normal_daily_centimes:null};return reply({data:vehicle},201);
    }
    if(u.pathname==='/api/v1/vehicles/900001'){
     if(req.method()==='PUT')vehicle={...vehicle,...JSON.parse(req.postData()),version:vehicle.version+1};
     return reply({data:vehicle,photos:[],readings:[],can_correct_odometer:true});
    }
    if(u.pathname.endsWith('/900001/documents')){
     if(req.method()==='GET')return reply({data:groups,today:'2026-11-02'});
     attempts++;const body=await req.fetchPostData();
     const field=name=>body.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([^\\r]*)'))?.[1];
     const kind=field('kind'),key=field('request_key');assert.ok(kinds.includes(kind));assert.ok(key);assert.equal(field('previous_id'),'');
     if(!keys.has(key)){
      const d={id:keys.size+1,kind,version:1,has_file:true,mime:'image/png',created_at:'2026-11-02T10:00:00Z',provider:field('provider')||null,effective_on:field('effective_on')||null,expires_on:field('expires_on')||null,deadline:{state:'ok',days:100}};
      keys.set(key,d);groups[kind]={latest:d,current:null,history:[d],total:1};
     }
     if(kind==='insurance'&&!lostResponse){lostResponse=true;return req.abort('failed');}
     return reply({data:keys.get(key)},201);
    }
    // Unrelated summary panels may fail to load in this isolated fixture; no real API fallthrough.
    return reply({message:'Outside test scope'},503);
   });
   await page.goto(base+'/qa-vehicle-create-documents',{waitUntil:'networkidle0'});
   await page.locator('.fleet-add').click();
   await page.type('input[name=plate]','QA-DOCS');await page.select('select[name=make_choice]','Dacia');await page.select('select[name=model_choice]','Duster');
   await page.click('#v-tab-odometer');await page.locator('.vehicle-footer .v-save').click();
   await page.waitForSelector('#v-tab-documents[aria-selected=true]');
   await page.focus('#v-tab-documents');await page.keyboard.press('ArrowRight');
   assert.equal(await page.$eval('#v-tab-info',e=>e.getAttribute('aria-selected')),'true');await page.click('#v-tab-documents');
   if(scenario==='upload-retry'){
    await page.locator('section[aria-label="Carte grise"] .secondary').click();
    await page.locator('.vehicle-footer button[type=submit]').click();
    await page.waitForFunction(()=>document.activeElement?.getAttribute('name')==='document_registration_file');assert.equal(creates,0);
    const file=await page.$('input[name=document_registration_file]');
    // Canceling the native file picker must not dismiss the vehicle dialog.
    await file.evaluate(e=>e.dispatchEvent(new Event('cancel',{bubbles:true})));
    assert.equal(await page.$eval('.vehicle-dialog',e=>e.open),true);
    await file.evaluate(e=>{const dt=new DataTransfer();dt.items.add(new File(['image fixture'],'registration.png',{type:'image/png'}));e.files=dt.files;e.dispatchEvent(new Event('change',{bubbles:true}));});
    await page.locator('section[aria-label="Assurance"] .secondary').click();
    await page.type('input[name=document_insurance_provider]','Assurance exemple');
    await page.$eval('input[name=document_insurance_effective_on]',e=>{const s=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;s.call(e,'2026-01-01');e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));});
    await page.$eval('input[name=document_insurance_expires_on]',e=>{const s=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;s.call(e,'2026-12-31');e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));});
   }
   for(const width of [320,768,1280]){
    await page.setViewport({width,height:950});
    assert.equal(await page.$eval('.vehicle-dialog',e=>e.scrollWidth>e.clientWidth+1),false,'dialog overflow '+width);
   }
   await page.screenshot({path:'/tmp/vehicle-create-documents-'+scenario+'.png',fullPage:true});
   await page.locator('.vehicle-footer button[type=submit]').click();
   if(scenario==='upload-retry'){
    await page.waitForFunction(()=>document.querySelector('.v-error-box')?.textContent.includes('envoi non confirmé'));
    assert.equal(creates,1);assert.equal(keys.size,2);
    await page.locator('.vehicle-footer button[type=submit]').click();
   }
   await page.waitForSelector('.v-file-workspace');
   assert.equal(creates,1);assert.equal(attempts,scenario==='skip'?0:3);assert.equal(keys.size,scenario==='skip'?0:2);
   if(scenario==='upload-retry'){assert.equal(groups.registration.total,1);assert.equal(groups.insurance.total,1);}
   await page.click('#vf-tab-documents');await page.waitForSelector('.v-doc-list');
   assert.equal(await page.$$eval('.v-doc-row',e=>e.length),5);
   assert.deepEqual(errors,[]);console.log(scenario+': passed (validation, navigation, responsive, same document endpoint, no duplicate)');
   await page.close();
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
