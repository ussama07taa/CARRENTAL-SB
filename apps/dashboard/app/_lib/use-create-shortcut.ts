'use client';
import {useEffect, useRef} from 'react';

/** Open the existing form once; reload/back must not reopen a dismissed form. */
export function useCreateShortcut(onCreate:()=>void) {
  const handled=useRef(false);
  useEffect(()=>{
    if(handled.current)return;
    handled.current=true;
    const url=new URL(window.location.href);
    if(url.searchParams.get('action')!=='create')return;
    url.searchParams.delete('action');
    window.history.replaceState(window.history.state,'',url.pathname+url.search+url.hash);
    onCreate();
  },[onCreate]);
}
