import type { FeedState } from '../lib/feed.ts'

/** Loading / error placeholder while the recommendations are not available. */
export function FeedStatus({ state }: { state: Exclude<FeedState, { status: 'ready' }> }) {
  if (state.status === 'loading') {
    return (
      <p className="empty" role="status">
        Loading recommendations…
      </p>
    )
  }
  return (
    <div className="empty empty--big" role="alert">
      <p>The recommendations could not be loaded right now.</p>
      <button type="button" className="btn" onClick={state.retry}>
        Try again
      </button>
    </div>
  )
}
