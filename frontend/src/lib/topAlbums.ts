import { useEffect, useState } from 'react'
import { loadTopAlbums } from '../data/source.ts'
import type { TopAlbum } from '../data/types.ts'
import { useFeedback } from './feedback.ts'

export type TopAlbumsState = { status: 'loading' } | { status: 'error' } | { status: 'ready'; albums: TopAlbum[] }

// One request per page load, shared by the menu and the "Bedøm albums" page.
let request: Promise<TopAlbum[]> | null = null

/** Your most played albums (GET /api/top-albums), most played first. Not loaded while `enabled` is false. */
export function useTopAlbums(enabled = true): TopAlbumsState {
  const [state, setState] = useState<TopAlbumsState>({ status: 'loading' })

  useEffect(() => {
    if (!enabled) return
    let active = true
    request ??= loadTopAlbums().then((r) => r.albums)
    request
      .then((albums) => active && setState({ status: 'ready', albums }))
      .catch((e: unknown) => {
        request = null
        if (active) {
          console.error(e)
          setState({ status: 'error' })
        }
      })
    return () => {
      active = false
    }
  }, [enabled])

  return state
}

/** Albums you have not rated yet, or null while the list or your ratings are loading. */
export function useUnratedAlbums(enabled = true): TopAlbum[] | null {
  const state = useTopAlbums(enabled)
  const feedback = useFeedback()
  if (state.status !== 'ready' || feedback.status === 'loading') return null
  return state.albums.filter((a) => feedback.get(a.release.id).rating === null)
}
