import { createContext, useContext } from 'react'
import type { Feed } from '../data/types.ts'

// The recommendations feed, loaded once per page load and shared by all pages
// (Discover and My Music show the same releases).

export type FeedState =
  | { status: 'loading' }
  | { status: 'error'; retry: () => void }
  | { status: 'ready'; feed: Feed }

export const FeedContext = createContext<FeedState | null>(null)

export function useFeed(): FeedState {
  const ctx = useContext(FeedContext)
  if (!ctx) throw new Error('useFeed must be used inside <FeedProvider>')
  return ctx
}
