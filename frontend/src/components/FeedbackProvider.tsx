import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { loadFeedback, saveFeedback } from '../data/source.ts'
import { useCurrentUser } from '../lib/auth.ts'
import type { FeedbackItem, Release } from '../data/types.ts'
import {
  EMPTY_FEEDBACK,
  FeedbackContext,
  applyChange,
  isEmpty,
  type FeedbackApi,
  type FeedbackChange,
  type ReleaseFeedback,
} from '../lib/feedback.ts'

interface Entry {
  feedback: ReleaseFeedback
  release: Release
}

const fromItem = (i: FeedbackItem): Entry => ({
  feedback: { rating: i.rating, notInterested: i.notInterested, saved: i.saved },
  release: i.release,
})

/**
 * Feedback stored on the server. Anyone can see it; changes need the login
 * (the API refuses them otherwise). Changes show immediately and are saved
 * in the background, one at a time per release; if saving fails the change
 * is undone and an error is shown.
 */
export function FeedbackProvider({ children }: { children: ReactNode }) {
  const [entries, setEntries] = useState<Record<string, Entry>>({})
  const [status, setStatus] = useState<FeedbackApi['status']>('loading')
  const [error, setError] = useState<string | null>(null)
  // Latest entries for the save callbacks (kept in sync after each render).
  const entriesRef = useRef(entries)
  useEffect(() => {
    entriesRef.current = entries
  }, [entries])
  const queues = useRef(new Map<string, Promise<unknown>>())
  const user = useCurrentUser()
  const loggedIn = useRef(user !== null)
  useEffect(() => {
    loggedIn.current = user !== null
  }, [user])

  useEffect(() => {
    const ctrl = new AbortController()
    loadFeedback(ctrl.signal)
      .then((items) => {
        setEntries((local) => ({ ...Object.fromEntries(items.map((i) => [i.releaseId, fromItem(i)])), ...local }))
        setStatus('ready')
      })
      .catch((e: unknown) => {
        if (!ctrl.signal.aborted) {
          console.error(e)
          setStatus('error')
        }
      })
    return () => ctrl.abort()
  }, [])

  const change = useCallback((release: Release, delta: FeedbackChange) => {
    if (!loggedIn.current) {
      setError('Log in to rate or mark releases.')
      return
    }
    const id = release.id
    const before = entriesRef.current[id]
    const next = applyChange(before?.feedback ?? EMPTY_FEEDBACK, delta)
    setEntries((all) => {
      const copy = { ...all }
      if (isEmpty(next)) delete copy[id]
      else copy[id] = { feedback: next, release: before?.release ?? release }
      return copy
    })

    const previous = queues.current.get(id) ?? Promise.resolve()
    const job = previous
      .then(() => saveFeedback(id, delta))
      .then((item) => {
        // The server's answer is the truth (and carries its own snapshot).
        setEntries((all) => {
          const copy = { ...all }
          const stored = fromItem(item)
          if (isEmpty(stored.feedback)) delete copy[id]
          else copy[id] = stored
          return copy
        })
      })
      .catch((e: unknown) => {
        console.error(e)
        const expired = e instanceof Error && e.message.startsWith('Log in')
        setEntries((all) => {
          const copy = { ...all }
          if (before) copy[id] = before
          else delete copy[id]
          return copy
        })
        setError(
          expired
            ? 'Your login has expired - log in again to rate or mark releases.'
            : `Couldn't save your change to "${release.title}" - it has been undone. Please try again.`,
        )
      })
    queues.current.set(id, job)
  }, [])

  const api = useMemo<FeedbackApi>(
    () => ({
      status,
      error,
      dismissError: () => setError(null),
      get: (id) => entries[id]?.feedback ?? EMPTY_FEEDBACK,
      releases: () => Object.values(entries).map((e) => e.release),
      rate: (release, stars) =>
        change(release, { rating: entries[release.id]?.feedback.rating === stars ? null : stars }),
      toggleNotInterested: (release) =>
        change(release, { notInterested: !(entries[release.id]?.feedback.notInterested ?? false) }),
      toggleSaved: (release) => change(release, { saved: !(entries[release.id]?.feedback.saved ?? false) }),
    }),
    [entries, status, error, change],
  )

  return <FeedbackContext.Provider value={api}>{children}</FeedbackContext.Provider>
}
