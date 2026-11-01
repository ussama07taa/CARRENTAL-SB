'use client';
import type {ReactNode} from 'react';
import './simple-section.css';
export function revealSection(id:string){
 const node=document.getElementById(id);if(!node)return;
 let parent:HTMLElement|null=node;
 while(parent){if(parent instanceof HTMLDetailsElement)parent.open=true;parent=parent.parentElement;}
 requestAnimationFrame(()=>{node.scrollIntoView({block:'start',behavior:'smooth'});if(node instanceof HTMLDetailsElement)node.querySelector('summary')?.focus({preventScroll:true});else node.focus({preventScroll:true});});
}
export default function SimpleSection({id,title,description,children,defaultOpen=false}:{id?:string;title:string;description?:string;children:ReactNode;defaultOpen?:boolean}){
 return <details id={id} className="simple-section" open={defaultOpen}><summary><span><strong>{title}</strong>{description&&<small>{description}</small>}</span><span className="simple-section-chevron" aria-hidden="true">⌄</span></summary><div className="simple-section-body">{children}</div></details>;
}
