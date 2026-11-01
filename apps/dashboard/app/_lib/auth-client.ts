export const api = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';
export async function authPost(path: 'login' | 'logout', body?: { email: string; password: string }) {
  return apiMutation(`auth/${path}`, 'POST', body ?? {});
}
export async function apiMutation(path: string, method: 'POST' | 'PUT', body: unknown, timeoutMs?: number) {
  const scope=document.activeElement?.closest<HTMLElement>('form,[role=dialog],dialog,section');
  const csrf = await fetch(`${api}/sanctum/csrf-cookie`, { credentials: 'include', headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(10000) });
  if (!csrf.ok) throw new Error('Connexion au serveur impossible. Réessayez.');
  const token = document.cookie.split('; ').find(item => item.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
  if (!token) throw new Error('Session impossible à initialiser. Autorisez les cookies puis réessayez.');
  const response=await fetch(`${api}/api/v1/${path}`, {
    method, credentials: 'include', cache: 'no-store',
    headers: { Accept: 'application/json', ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), 'X-XSRF-TOKEN': decodeURIComponent(token) },
    body: body instanceof FormData ? body : JSON.stringify(body ?? {}), signal: AbortSignal.timeout(timeoutMs ?? (body instanceof FormData ? 60000 : 10000)),
  });
  if(response.status===422&&scope){try{const result=await response.clone().json();if(result.errors)document.dispatchEvent(new CustomEvent('form-server-errors',{detail:{scope,errors:result.errors}}));}catch{/* Keep the original response available to the caller. */}}
  return response;
}
