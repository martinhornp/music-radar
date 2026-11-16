// Small fetch wrapper for the PHP API. In development Vite proxies /api to
// the local PHP server; in production the API lives at /api on the same host.
const API_BASE = import.meta.env.VITE_API_BASE ?? '/api'

export async function apiGet<T>(path: string, signal?: AbortSignal): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    headers: { Accept: 'application/json' },
    signal,
  })
  if (!res.ok) {
    throw new Error(`API ${res.status}: ${res.statusText}`)
  }
  return (await res.json()) as T
}

/** POST a JSON body; resolves with the JSON response, rejects with the API's error message. */
export async function apiPost<T>(path: string, body: unknown): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = (await res.json().catch(() => null)) as (T & { error?: string }) | null
  if (!res.ok) {
    throw new Error(data?.error ?? `API ${res.status}`)
  }
  return data as T
}
