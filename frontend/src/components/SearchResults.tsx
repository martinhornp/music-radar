import { useEffect, useState } from 'react'
import { searchReleases } from '../data/source.ts'
import type { Release, SearchResponse } from '../data/types.ts'
import { formatDate } from '../lib/format.ts'
import { ReleaseCard } from './ReleaseCard.tsx'

/** Shortest search that is sent to the server. */
export const MIN_SEARCH = 2

type State = { status: 'loading' } | { status: 'error' } | { status: 'ready'; data: SearchResponse }
type Answer = { query: string } & ({ status: 'error' } | { status: 'ready'; data: SearchResponse })

/**
 * Results of a search in everything the radar has stored - independent of
 * the Discover filters (a search finds releases outside "Last 7 days" too).
 */
export function SearchResults({ query, onOpen }: { query: string; onOpen: (release: Release) => void }) {
  const [answer, setAnswer] = useState<Answer | null>(null)
  // Until the answer for this exact query is in, it is "searching".
  const state: State = answer && answer.query === query ? answer : { status: 'loading' }

  useEffect(() => {
    const ctrl = new AbortController()
    // Wait until typing pauses, so not every key press is a request.
    const timer = setTimeout(() => {
      searchReleases(query, ctrl.signal)
        .then((data) => setAnswer({ query, status: 'ready', data }))
        .catch(() => {
          if (!ctrl.signal.aborted) setAnswer({ query, status: 'error' })
        })
    }, 250)
    return () => {
      clearTimeout(timer)
      ctrl.abort()
    }
  }, [query])

  return (
    <section className="section" aria-labelledby="search-title" aria-busy={state.status === 'loading'}>
      <header className="section__head">
        <h2 id="search-title" className="section__title">
          Search results
          {state.status === 'ready' && <span className="section__count">{state.data.results.length}{state.data.more ? '+' : ''}</span>}
        </h2>
        <p className="section__sub">
          For “{query}” in everything the radar has found – recommended or not, any release date
        </p>
      </header>

      {state.status === 'loading' && (
        <p className="empty" role="status">
          Searching…
        </p>
      )}
      {state.status === 'error' && <p className="empty">Search is unavailable right now. Please try again later.</p>}
      {state.status === 'ready' && state.data.results.length === 0 && <NotFound query={query} coverage={state.data.coverage} />}
      {state.status === 'ready' && state.data.results.length > 0 && (
        <>
          <div className="releases releases--grid">
            {state.data.results.map(({ status, release }) => (
              <ReleaseCard
                key={release.id}
                release={release}
                status={status}
                variant={status === 'recommended' ? 'recommended' : status === 'wildcard' ? 'wildcard' : 'plain'}
                onOpen={onOpen}
              />
            ))}
          </div>
          {state.data.more && <p className="search__more">Showing the best {state.data.results.length} matches – add a word to narrow the search.</p>}
        </>
      )}
    </section>
  )
}

/** "Not found" - about our database only, never a claim that the release does not exist. */
function NotFound({ query, coverage }: { query: string; coverage: SearchResponse['coverage'] }) {
  return (
    <div className="empty search__none">
      <p>No release matching “{query}” in the radar’s database.</p>
      <p className="search__note">
        That doesn’t mean it doesn’t exist. The radar knows {coverage.releases.toLocaleString('en-GB')} albums and EPs
        {coverage.from ? ` first released from ${formatDate(coverage.from)}` : ''} that are registered on MusicBrainz – a
        release that is older, not yet registered there, or a single will not be found.
      </p>
    </div>
  )
}
