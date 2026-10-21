import { headers } from 'next/headers';

export type Session = { user: { name: string }; company: { name: string }; responsible: { name: string }; demo: boolean };
export async function readSession(): Promise<{ status: number; session: Session | null }> {
  const incoming = await headers();
  const response = await fetch(`${process.env.API_INTERNAL_URL ?? 'http://127.0.0.1:8000'}/api/v1/auth/me`, {
    headers: { Accept: 'application/json', Cookie: incoming.get('cookie') ?? '' },
    cache: 'no-store', signal: AbortSignal.timeout(5000),
  });
  if (response.status >= 500) throw new Error('Service indisponible');
  return { status: response.status, session: response.ok ? await response.json() as Session : null };
}
