import { useEffect, useState, type ReactNode } from 'react'
import type { Feed } from '../data/types.ts'
import { loadFeed } from '../data/source.ts'
import { FeedContext, type FeedState } from '../lib/feed.ts'

export function FeedProvider({ children }: { children: ReactNode }) {
  const [feed, setFeed] = useState<Feed | null>(null)
  const [failed, setFailed] = useState(false)
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    const ctrl = new AbortController()
    loadFeed(ctrl.signal)
      .then(setFeed)
      .catch((e: unknown) => {
        if (!ctrl.signal.aborted) {
          console.error(e)
          setFailed(true)
        }
      })
    return () => ctrl.abort()
  }, [attempt])

  const state: FeedState = feed
    ? { status: 'ready', feed }
    : failed
      ? {
          status: 'error',
          retry: () => {
            setFailed(false)
            setAttempt((n) => n + 1)
          },
        }
      : { status: 'loading' }

  return <FeedContext.Provider value={state}>{children}</FeedContext.Provider>
}
