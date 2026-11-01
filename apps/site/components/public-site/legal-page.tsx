import {ArrowRight,Globe,MessageCircle,Info,ExternalLink} from 'lucide-react';
import type {ContactLanguage} from '../../lib/whatsapp';
import {faqCopy} from './faq-copy';
import {legalCopy} from './legal-copy';
import '@fontsource-variable/manrope';
import './contact-page.css';
import './faq-page.css';
import './legal-page.css';
export default function LegalPage({language,kind}:{language:ContactLanguage;kind:'terms'|'privacy'}){const t=faqCopy[language],l=legalCopy[language],title=l[kind],path=kind==='terms'?'conditions':'confidentialite',sections=kind==='terms'?l.termsSections:l.privacySections;return <div className="cp fq legal" lang={language} dir={language==='ar'?'rtl':'ltr'}><a className="cp-skip" href="#legal-main">{title}</a>

<main id="legal-main"><div className="fq-intro"><span className="cp-eyebrow">BEN SBIH · {title}</span><h1>{title}</h1><p>{kind==='terms'?l.termsNote:l.privacyNote}</p></div><div className="legal-notice" role="note"><Info size={20}/><span>{l.notice}</span></div><div className="fq-layout"><aside><nav aria-label={l.contents}>{sections.map(([heading],i)=><a key={heading} href={`#legal-${i}`}><span>0{i+1}</span>{heading}<ArrowRight size={16}/></a>)}</nav><div className="fq-help"><MessageCircle size={26}/><h2>{t.help}</h2><p>{t.helpText}</p><a href={`/${language}/contact`}>{t.contact}<ArrowRight size={16}/></a></div></aside><div className="fq-groups">{sections.map(([heading,body],i)=><section id={`legal-${i}`} key={heading}><h2><span>0{i+1}</span>{heading}</h2><p>{body}</p></section>)}{kind==='privacy'&&<a className="legal-source" href="https://www.cndp.ma/personnes-concernees/" target="_blank" rel="noopener noreferrer">{l.source}<ExternalLink size={16}/></a>}</div></div></main></div>}
