const fs=require('node:fs');const vm=require('node:vm');const assert=require('node:assert/strict');const ts=require('typescript');const React=require('react');const {renderToStaticMarkup}=require('react-dom/server');
const source=fs.readFileSync('components/website/vehicle-gallery.tsx','utf8');
const compiled=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX,esModuleInterop:true}}).outputText;
let selection={currency:'MAD',rates:null};
const moduleObject={exports:{}};vm.runInNewContext(compiled,{exports:moduleObject.exports,module:moduleObject,require:(id)=>id.endsWith('.css')?{}:id==='./display-currency'?{useDisplayCurrency:()=>selection,currencyNotice:{}}:require(id)});
for(const language of ['fr','ar','en','es']){
 assert.equal(renderToStaticMarkup(React.createElement(moduleObject.exports.DailyPrice,{value:null,language})), '');
 const actual=renderToStaticMarkup(React.createElement(moduleObject.exports.DailyPrice,{value:45000,language}));assert.match(actual,/data-daily-price/);assert.match(actual,/DH/);
}
console.log('PASS: missing price renders no price line in all four languages; configured price renders.');

for(const currency of ['USD','EUR']){
 selection={currency,rates:{updated:Date.now(),USD:0.1,EUR:0.09}};
 for(const language of ['fr','ar','en','es']){
  const markup=renderToStaticMarkup(React.createElement(moduleObject.exports.DailyPrice,{value:45000,language}));
  assert.match(markup,currency==='USD'?/\$45</:/€40</);
  assert.match(markup,/dir="ltr"/);assert.match(markup,/450.*MAD/);assert.doesNotMatch(markup,/≈/);
 }
}
console.log('PASS: EUR/USD symbols, whole-number display, LTR isolation and MAD reference in all languages.');
