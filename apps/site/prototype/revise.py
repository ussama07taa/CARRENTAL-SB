from pathlib import Path
p=Path('/home/taaouati/projects/bensebih-car-rental/apps/site/prototype/app.js')
s=p.read_text(encoding='utf-8-sig')
a=s.index('return `<div class="hero">',s.index('function home()'))
b=s.index('<section class="section" id="fleet">',a)
s=s[:a]+'''return `<section class="arrival"><div class="arrival-copy"><div class="eyebrow">${t('LOCATION DE VOITURES · TANGER','كراء السيارات · طنجة')}</div><h1>${t('Votre séjour.<br>Votre voiture.<br><em>Votre liberté.</em>','إقامتك.<br>سيارتك.<br><em>حريتك.</em>')}</h1><p>${t('À l’aéroport ou à votre hôtel, votre prochaine route commence avec BEN SBIH.','فالمطار ولا فالفندق، طريقك الجاية كتبدا مع BEN SBIH.')}</p><a href="#fleet" data-fleet class="text-cta">${t('Explorer la collection','اكتشف السيارات')} <span>↗</span></a></div><div class="arrival-photo"><img src="assets/troc.jpg" alt="Volkswagen T-Roc"><span class="photo-tag">TANGER, MAROC · 35.76° N</span><div class="photo-caption"><span>01 / 03</span><strong>Volkswagen<br>T-Roc</strong><span>${t('SUV · Collection démo','SUV · مجموعة تجريبية')}</span></div></div></section><div class="booking-block"><div class="booking-heading"><span class="booking-icon">↗</span><div><h2>${t('Où commence votre séjour ?','فين غادي تبدا رحلتك؟')}</h2><span>${t('Vos dates. Votre lieu. On s’occupe de la suite.','التواريخ والمكان ديالك، والباقي نتفاهمو عليه.')}</span></div></div>${search()}</div><div class="journey"><div><b>01</b><span>${t('Choisissez votre voiture','اختار سيارتك')}</span></div><i>→</i><div><b>02</b><span>${t('Envoyez votre demande','صيفط الطلب')}</span></div><i>→</i><div><b>03</b><span>${t('Confirmez avec l’agence','أكد مع الوكالة')}</span></div></div>'''+s[b:]
s=s.replace("t('Choisissez votre prochaine aventure.','اختار رحلتك الجاية.')","t('Une voiture pour chaque envie.','سيارة لكل رحلة.')")
s=s.replace('<div class="car-bottom"><span>', '<div class="car-bottom"><span>')
s=s.replace("t('Et si votre séjour commençait ici ?','رحلتك كتبدا من هنا.')","t('Organisons votre arrivée.','ننظمو وصولك.')")
s=s.replace('<aside class="panel summary"><div class="eyebrow">','<aside class="panel summary"><img class="recap-car" src="assets/${car().image}.jpg" alt="${car().name}"><div class="eyebrow">')
s=s.replace('<a href="#delivery" data-delivery class="nav-extra">','<a href="#delivery" data-delivery class="nav-extra">')
s=s.replace('<button id="language">','<a href="#fleet" data-fleet class="nav-book nav-extra">${t("Trouver ma voiture","نلقى سيارتي")} ↗</a><button id="language">')
p.write_text(s,encoding='utf-8')
