import {ArrowRight,ChevronDown,Globe,MessageCircle} from 'lucide-react';
import type {ContactLanguage} from '../../lib/whatsapp';
import {faqCopy} from './faq-copy';
import '@fontsource-variable/manrope';
import './contact-page.css';
import './faq-page.css';
export default function FaqPage({language}:{language:ContactLanguage}){const t=faqCopy[language];return <div className="cp fq" lang={language} dir={language==='ar'?'rtl':'ltr'}><a className="cp-skip" href="#faq-main">{t.label}</a>
<main id="faq-main"><div className="fq-intro"><span className="cp-eyebrow">BEN SBIH · {t.label}</span><h1>{t.title}</h1><p>{t.intro}</p></div><div className="fq-layout"><aside><nav aria-label={t.label}>{t.groups.map((g,i)=><a href={`#faq-group-${i}`} key={g}><span>0{i+1}</span>{g}<ArrowRight size={16}/></a>)}</nav><div className="fq-help"><MessageCircle size={26}/><h2>{t.help}</h2><p>{t.helpText}</p><a href={`/${language}/contact`}>{t.contact}<ArrowRight size={16}/></a></div></aside><div className="fq-groups">{t.groups.map((g,i)=><section id={`faq-group-${i}`} key={g}><h2><span>0{i+1}</span>{g}</h2>{t.items.filter(item=>item[0]===i).map((item,j)=><details className="fq-question" key={item[1]} name={`faq-${i}`} open={i===0&&j===0}><summary>{item[1]}<ChevronDown size={20}/></summary><p>{item[2]}</p></details>)}</section>)}</div></div></main></div>}
