// External services shown under Settings → Connected services: which ones
// Music Radar actually uses (static - it describes the set-up, it does not
// check the service right now).

export type ServiceStatus = 'connected'

export interface Service {
  id: string
  name: string
  purpose: string
  status: ServiceStatus
  /** Account/username at the service, if any. */
  account?: string
}

export const SERVICE_STATUS_LABEL: Record<ServiceStatus, string> = {
  connected: 'In use',
}

const SERVICES: Service[] = [
  {
    id: 'lastfm',
    name: 'Last.fm',
    purpose: 'Your listening history, artist and album tags, and Similar Artists',
    status: 'connected',
    account: 'MartinHornP',
  },
  {
    id: 'musicbrainz',
    name: 'MusicBrainz',
    purpose: 'New releases, artists, genre tags and cover art - no account needed',
    status: 'connected',
  },
]

export function getServices(): Service[] {
  return SERVICES
}
