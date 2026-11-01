'use client';
import {useState} from 'react';
import SiteHeader,{HeaderLanguage,HeaderDestination} from '../../../components/site-header/header';
import './preview.css';
export default function HeaderPreview(){
 const [language,setLanguage]=useState<HeaderLanguage>('fr'),[active,setActive]=useState<HeaderDestination>('home'),[clicked,setClicked]=useState(false);
 const labels={home:'Accueil',cars:'Nos voitures',contact:'Contact',request:'Demande de réservation'};
 return <div className="hp-page"><SiteHeader language={language} active={active} onLanguage={setLanguage} onNavigate={next=>{setActive(next);setClicked(true);}}/><main className="hp-stage"><div className="hp-line"/><div className="hp-label"><span>01</span> ÉTUDE DE L’EN-TÊTE</div><h1>Une entrée claire.<br/><span>La suite, étape par étape.</span></h1><p>Logo, navigation, langues et accès à la réservation.<br/>Seul l’en-tête est à valider ici.</p><div className="hp-feedback" role="status">{clicked?<>Aperçu du lien : <strong>{labels[active]}</strong><br/>La page correspondante sera travaillée ensuite.</>:<>Essayez le menu et les quatre langues.</>}</div><a href="/design-lab/header/mobile" className="hp-mobile-link">Voir à 390 px ↗</a></main></div>
}
