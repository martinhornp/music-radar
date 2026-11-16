import { useEffect, useMemo, useState } from 'react'
import { FeedStatus } from '../components/FeedStatus.tsx'
import { FilterBar } from '../components/FilterBar.tsx'
import { DiceIcon, SparkIcon } from '../components/Icons.tsx'
import { ReleaseDetail } from '../components/ReleaseDetail.tsx'
import { ReleaseSection } from '../components/ReleaseSection.tsx'
import { SearchBox } from '../components/SearchBox.tsx'
import { MIN_SEARCH, SearchResults } from '../components/SearchResults.tsx'
import type { Release } from '../data/types.ts'
import { setPageTitle } from '../lib/app.ts'
import { useFeed } from '../lib/feed.ts'
import { useFeedback } from '../lib/feedback.ts'
import {
  DISCOVER_DATE_OPTIONS,
  DISCOVER_FILTERS,
  applyFilters,
  availableGenres,
  recommendedPicks,
  wildcardPicks,
  type Filters,
} from '../lib/releases.ts'

/** Recommended cards rendered at first and per "Load more". */
const PAGE_SIZE = 24

export function Discover() {
  const state = useFeed()
  const [query, setQuery] = useState('')
  // Kept here (not in the feed view), so clearing a search returns to the same filters.
  const [filters, setFilters] = useState<Filters>(DISCOVER_FILTERS)
  const [open, setOpen] = useState<Release | null>(null)
  const search = query.trim()
  const searching = search.length >= MIN_SEARCH

  useEffect(() => setPageTitle(), [])

  return (
    <>
      <div className="page-head">
        <p className="kicker">Discover</p>
        <h1 className="page-title">Albums &amp; EPs picked for your taste</h1>
      </div>

      <SearchBox value={query} onChange={setQuery} />

      {searching ? (
        <SearchResults query={search} onOpen={setOpen} />
      ) : state.status === 'ready' ? (
        <DiscoverFeed releases={state.feed.releases} filters={filters} onFilters={setFilters} onOpen={setOpen} />
      ) : (
        <FeedStatus state={state} />
      )}

      <ReleaseDetail release={open} onClose={() => setOpen(null)} />
    </>
  )
}

interface FeedProps {
  releases: Release[]
  filters: Filters
  onFilters: (f: Filters) => void
  onOpen: (r: Release) => void
}

function DiscoverFeed({ releases, filters, onFilters: setFilters, onOpen: setOpen }: FeedProps) {
  const feedback = useFeedback()
  // "Not interested" hides a release right away (undo it in My Music).
  const visible = releases.filter((r) => !feedback.get(r.id).notInterested)
  const filtered = applyFilters(visible, filters)
  const genres = useMemo(() => availableGenres(releases), [releases])
  const recommended = recommendedPicks(filtered)
  const wildcards = wildcardPicks(filtered)
  const empty = 'Nothing matches your filters here. Try widening them.'

  return (
    <>
      <FilterBar
        genres={genres}
        value={filters}
        onChange={setFilters}
        resultCount={filtered.length}
        defaults={DISCOVER_FILTERS}
        dateOptions={DISCOVER_DATE_OPTIONS}
      />

      <ReleaseSection
        // New filters start the list from the top again.
        key={JSON.stringify(filters)}
        id="recommended"
        title="Recommended for You"
        icon={<SparkIcon className="section__icon" />}
        releases={recommended}
        variant="recommended"
        layout="feature"
        emptyText={empty}
        pageSize={PAGE_SIZE}
        onOpen={setOpen}
      />

      <ReleaseSection
        id="wildcards"
        title="Wildcards"
        subtitle="Less obvious picks - the radar takes a chance and tells you why"
        icon={<DiceIcon className="section__icon" />}
        releases={wildcards}
        variant="wildcard"
        layout="row"
        tone="wildcard"
        emptyText={empty}
        onOpen={setOpen}
      />
    </>
  )
}
