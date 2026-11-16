import { useSyncExternalStore } from 'react'

// Minimal path router on top of the History API. Apache's SPA fallback
// (.htaccess) serves index.html for every route, so deep links work.

export const ROUTES = {
  discover: '/',
  myMusic: '/martin-ratings',
  rateAlbums: '/bedoem-albums',
  settings: '/settings',
  login: '/login',
} as const

/** Old addresses that still work: they are replaced by the new one. */
export const MOVED: Record<string, string> = {
  '/my-music': ROUTES.myMusic,
}

const NAV_EVENT = 'app:navigate'

function subscribe(onChange: () => void) {
  window.addEventListener('popstate', onChange)
  window.addEventListener(NAV_EVENT, onChange)
  return () => {
    window.removeEventListener('popstate', onChange)
    window.removeEventListener(NAV_EVENT, onChange)
  }
}

export function usePath(): string {
  return useSyncExternalStore(subscribe, () => window.location.pathname)
}

/** Like navigate(), but without a history entry (for a moved address). */
export function redirect(path: string) {
  window.history.replaceState(null, '', path)
  window.dispatchEvent(new Event(NAV_EVENT))
}

export function navigate(path: string) {
  if (path === window.location.pathname) return
  window.history.pushState(null, '', path)
  window.dispatchEvent(new Event(NAV_EVENT))
  window.scrollTo({ top: 0 })
}
