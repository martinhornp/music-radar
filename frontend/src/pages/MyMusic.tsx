import { useEffect, useMemo, useState } from 'react'
import { FeedStatus } from '../components/FeedStatus.tsx'
import { FilterBar } from '../components/FilterBar.tsx'
import { ReleaseDetail } from '../components/ReleaseDetail.tsx'
import { ReleaseSection } from '../components/ReleaseSection.tsx'
import type { Feed, Release } from '../data/types.ts'
import { setPageTitle } from '../lib/app.ts'
import { useFeed } from '../lib/feed.ts'
import { useFeedback, type FeedbackApi } from '../lib/feedback.ts'
import { DEFAULT_FILTERS, applyFilters, availableGenres, type Filters } from '../lib/releases.ts'

type Tab = 'saved' | 'rated' | 'not-interested'

const TABS: { id: Tab; label: string; subtitle: string; empty: string }[] = [
  {
    id: 'saved',
    label: 'Saved for later',
    subtitle: 'Releases you want to listen to',
    empty: 'Nothing saved yet. Use "Save for later" on a release.',
  },
  {
    id: 'rated',
    label: 'Rated',
    subtitle: "Releases you've listened to and rated - highest first",
    empty: "You haven't rated anything yet. Open a release and give it 1-5 stars.",
  },
  {
    id: 'not-interested',
    label: 'Not interested',
    subtitle: "Releases you don't want to hear - used to steer future picks",
    empty: 'Nothing marked as not interested.',
  },
]

function select(releases: Release[], tab: Tab, fb: FeedbackApi): Release[] {
  switch (tab) {
    case 'saved':
      return releases.filter((r) => fb.get(r.id).saved)
    case 'rated':
      return releases
        .filter((r) => fb.get(r.id).rating)
        .sort((a, b) => (fb.get(b.id).rating ?? 0) - (fb.get(a.id).rating ?? 0))
    case 'not-interested':
      return releases.filter((r) => fb.get(r.id).notInterested)
  }
}

export function MyMusic() {
  const state = useFeed()

  useEffect(() => setPageTitle('Martins ratings'), [])

  return (
    <>
      <div className="page-head">
        <p className="kicker">Martins ratings</p>
        <h1 className="page-title">Your ratings &amp; saved releases</h1>
      </div>

      {state.status === 'ready' ? <MyReleases feed={state.feed} /> : <FeedStatus state={state} />}
    </>
  )
}

function MyReleases({ feed }: { feed: Feed }) {
  const feedback = useFeedback()
  const { taste } = feed
  // Everything you have marked: the current feed version if the release is
  // still in it, otherwise the version saved with your feedback.
  const inFeed = useMemo(() => new Map(feed.releases.map((r) => [r.id, r])), [feed])
  const marked = feedback.releases()
  const all = useMemo(() => marked.map((r) => inFeed.get(r.id) ?? r), [marked, inFeed])
  const [tab, setTab] = useState<Tab>('saved')
  const [filters, setFilters] = useState<Filters>(DEFAULT_FILTERS)
  const [open, setOpen] = useState<Release | null>(null)

  const genres = useMemo(() => availableGenres(all), [all])
  const filtered = applyFilters(all, filters)
  const lists = Object.fromEntries(TABS.map((t) => [t.id, select(filtered, t.id, feedback)])) as Record<Tab, Release[]>
  const active = TABS.find((t) => t.id === tab)!

  return (
    <>
      <div className="tabs" role="tablist" aria-label="My releases">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            id={`tab-${t.id}`}
            aria-selected={tab === t.id}
            aria-controls="tab-panel"
            className="tab"
            onClick={() => setTab(t.id)}
          >
            {t.label}
            <span className="tab__count">{lists[t.id].length}</span>
          </button>
        ))}
      </div>

      <FilterBar genres={genres} value={filters} onChange={setFilters} resultCount={lists[tab].length} />

      <div id="tab-panel" role="tabpanel" aria-labelledby={`tab-${tab}`}>
        <ReleaseSection
          id={tab}
          title={active.label}
          subtitle={active.subtitle}
          releases={lists[tab]}
          variant="plain"
          layout="grid"
          emptyText={active.empty}
          onOpen={setOpen}
        />
      </div>

      <section className="taste" aria-labelledby="taste-title">
        <h2 id="taste-title" className="section__title">
          Taste profile
        </h2>
        <p className="section__sub">What your picks are based on - from your Last.fm listening history</p>
        <div className="taste__groups">
          <div>
            <h3 className="taste__label">Top genres</h3>
            <ul className="tags tags--big">
              {taste.genres.map((g) => (
                <li key={g}>{g}</li>
              ))}
            </ul>
          </div>
          <div>
            <h3 className="taste__label">Artists you like</h3>
            <ul className="tags tags--big">
              {taste.artists.map((a) => (
                <li key={a}>{a}</li>
              ))}
            </ul>
          </div>
        </div>
      </section>

      <ReleaseDetail release={open} onClose={() => setOpen(null)} />
    </>
  )
}
