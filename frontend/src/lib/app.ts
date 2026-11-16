/** The app's user-facing name. Also hard-coded in index.html <title>. */
export const APP_NAME = 'Martins Music Radar'

export function setPageTitle(page?: string) {
  document.title = page ? `${page} · ${APP_NAME}` : APP_NAME
}
