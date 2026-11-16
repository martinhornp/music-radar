import { createContext, useContext } from 'react'
import type { Release } from '../data/types.ts'

// The listener's feedback on releases - three distinct signals that the
// recommendation engine uses differently:
//
//   rating         1-5 stars: "I've listened to it, this is what I think" -
//                  teaches the engine (from its next recalculation)
//   notInterested  "I don't want to hear this" - hides the release, but is
//                  NOT a low rating and teaches nothing about the music
//   saved          "Save for later" - a bookmark, ignored by the engine
//
// A rating and "not interested" exclude each other (you either listened or
// you don't want to). "Not interested" also removes the bookmark; saving
// removes "not interested". The server applies the same rules.
//
// Stored on the server (GET/POST /api/feedback), keyed by the release's
// MusicBrainz id. No login yet: belongs to the single site owner.

export type Stars = 1 | 2 | 3 | 4 | 5

export interface ReleaseFeedback {
  rating: Stars | null
  notInterested: boolean
  saved: boolean
}

export interface FeedbackApi {
  /** 'loading' until the stored feedback is in; 'error' if it could not be read. */
  status: 'loading' | 'ready' | 'error'
  /** Last save that failed (the change was undone), until dismissed. */
  error: string | null
  dismissError(): void
  get(id: string): ReleaseFeedback
  /** Every release with feedback, as it was when marked - also when it has left the feed. */
  releases(): Release[]
  /** Set a rating; giving the same rating again removes it. */
  rate(release: Release, stars: Stars): void
  toggleNotInterested(release: Release): void
  toggleSaved(release: Release): void
}

export const EMPTY_FEEDBACK: ReleaseFeedback = { rating: null, notInterested: false, saved: false }

export function isEmpty(f: ReleaseFeedback): boolean {
  return f.rating === null && !f.notInterested && !f.saved
}

/** The change one action makes - mirrors the server's rules. */
export type FeedbackChange = { rating: Stars | null } | { notInterested: boolean } | { saved: boolean }

export function applyChange(f: ReleaseFeedback, change: FeedbackChange): ReleaseFeedback {
  if ('rating' in change) {
    return { ...f, rating: change.rating, notInterested: change.rating === null ? f.notInterested : false }
  }
  if ('notInterested' in change) {
    return change.notInterested ? { rating: null, saved: false, notInterested: true } : { ...f, notInterested: false }
  }
  return { ...f, saved: change.saved, notInterested: change.saved ? false : f.notInterested }
}

export const FeedbackContext = createContext<FeedbackApi | null>(null)

export function useFeedback(): FeedbackApi {
  const ctx = useContext(FeedbackContext)
  if (!ctx) throw new Error('useFeedback must be used inside <FeedbackProvider>')
  return ctx
}
