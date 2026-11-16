import type { Release } from '../data/types.ts'

export function TidalLink({ release }: { release: Release }) {
  const id = release.tidalAlbumId
  if (!id || !/^[1-9][0-9]*$/.test(id)) return null

  // Explicit album navigation, never a play/autoplay link or a search fallback.
  // Android/iOS keep their verified HTTPS link handling; Windows uses the
  // desktop protocol Martin tested. The browser may ask for permission.
  const windows = /Windows/i.test(navigator.userAgent) && !/Android/i.test(navigator.userAgent)
  const href = windows ? `tidal://album/${id}` : `https://tidal.com/album/${id}`

  return (
    <a className="tidal-link" href={href} aria-label={`Listen to ${release.title} by ${release.artist} on TIDAL`}>
      Listen on TIDAL
    </a>
  )
}
