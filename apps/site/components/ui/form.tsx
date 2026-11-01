import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react';
export function Button({className='',...props}:ButtonHTMLAttributes<HTMLButtonElement>){return <button className={`bs-button ${className}`} {...props}/>}
export function Input({className='',...props}:InputHTMLAttributes<HTMLInputElement>){return <input className={`bs-input ${className}`} {...props}/>}
export function Field({id,label,error,children}:{id:string;label:string;error?:string;children:ReactNode}){return <div className="bs-field"><label htmlFor={id}>{label}</label>{children}{error&&<span id={`${id}-error`} role="alert" className="bs-error">{error}</span>}</div>}
