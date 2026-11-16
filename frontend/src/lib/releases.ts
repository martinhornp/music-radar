// Presentation-independent logic: filtering, the Discover sections and small
// derived values.
import { NO_GENRE, type Release, type ReleaseType, type ScorePart } from '../data/types.ts'
import { daysSince } from './format.ts'

/** Release types shown in this version. Singles can be added here later. */
export const SUPPORTED_TYPES: ReleaseType[] = ['Album', 'EP']

export type TypeFilter = 'all' | ReleaseType
export type DateFilter = 'any' | '7' | '14' | '31'

export interface Filters {
  genres: string[] // empty = all genres
  type: TypeFilter
  date: DateFilter
}

export const DEFAULT_FILTERS: Filters = { genres: [], type: 'all', date: 'any' }

/** Discover opens on the newest releases; the whole window is one click away. */
export const DISCOVER_FILTERS: Filters = { ...DEFAULT_FILTERS, date: '7' }

export type DateOption = { value: DateFilter; label: string }

export const DATE_OPTIONS: DateOption[] = [
  { value: 'any', label: 'Any time' },
  { value: '7', label: 'Last 7 days' },
  { value: '14', label: 'Last 14 days' },
  { value: '31', label: 'Last 31 days' },
]

/** Discover covers the radar's window (today and the 30 days before), so "Any time" would equal "Last 31 days". */
export const DISCOVER_DATE_OPTIONS: DateOption[] = DATE_OPTIONS.filter((o) => o.value !== 'any')

/** True when the filters differ from the page's starting point. */
export function isFiltered(f: Filters, defaults: Filters = DEFAULT_FILTERS): boolean {
  return f.genres.length > 0 || f.type !== defaults.type || f.date !== defaults.date
}

/** Genre label used for display and filtering. */
export function genreOf(r: Release): string {
  return r.genre ?? NO_GENRE
}

export function applyFilters(releases: Release[], f: Filters): Release[] {
  return releases.filter(
    (r) =>
      SUPPORTED_TYPES.includes(r.type) &&
      (f.genres.length === 0 || f.genres.includes(genreOf(r))) &&
      (f.type === 'all' || r.type === f.type) &&
      // "Last 7 days" = today and the 6 days before it (today counts as day 1).
      (f.date === 'any' || daysSince(r.releaseDate) < Number(f.date)),
  )
}

// The engine's order: known artists first, then best fit, with near-identical
// picks spread out.
const byRank = (a: Release, b: Release) => a.pick.rank - b.pick.rank

const isKnown = (r: Release) => r.origin === 'following' || r.origin === 'interested'

/**
 * Recommended for You: releases from artists you follow or are interested in
 * first, then everything else - each group by score, highest first. Equal
 * scores: newest release first, then MusicBrainz id (stable order). Without
 * scores (an older feed) the engine's order is kept.
 */
export function byPriority(a: Release, b: Release): number {
  if (isKnown(a) !== isKnown(b)) return isKnown(a) ? -1 : 1
  if (a.score == null || b.score == null) return byRank(a, b)
  return b.score - a.score || b.releaseDate.localeCompare(a.releaseDate) || a.id.localeCompare(b.id)
}

/** Matches with the listener's taste: known artists first, then by score. */
export function recommendedPicks(releases: Release[]): Release[] {
  return releases.filter((r) => r.pick.kind === 'recommended').sort(byPriority)
}

const SCORE_PART_LABELS: Record<ScorePart, string> = {
  genre: 'genres you listen to',
  lastfm: 'Last.fm Similar Artists',
  // Before model v2 (shown until the next calculation):
  neighbours: 'genre tags shared with artists you play',
  family: 'genre families you like',
  blend: 'mix of genre families you like',
  danish: 'Danish artist',
  known: 'an artist you know',
  rating: 'your ratings and listening',
  outside: 'outside your usual genres',
}

/** Specifics from the release's own reasons, e.g. which artists or genres. */
function scorePartDetail(r: Release, part: ScorePart): string {
  const reason = (kind: string) => r.reasons.find((x) => x.kind === kind)?.text ?? ''
  switch (part) {
    case 'neighbours':
      return reason('neighbours').match(/close to (.+?) \(shared/)?.[1] ?? ''
    case 'genre':
      return reason('genres').replace(/^(Matches genres|Genres) you listen to: /, '')
    case 'lastfm':
      return reason('lastfm').match(/connects it to (.+?), in your/)?.[1] ?? ''
    case 'blend':
      return reason('blend').match(/^Combines (.+?), both/)?.[1] ?? ''
    default:
      return ''
  }
}

/**
 * Tooltip explaining how this release's score adds up: each signal's
 * contribution, largest first, with specifics where the release has them.
 */
export function scoreExplanation(r: Release): string {
  if (r.score == null) return ''
  const parts = Object.entries(r.scoreParts ?? {})
    .filter(([, v]) => Math.abs(v ?? 0) >= 0.005)
    .sort(([, a], [, b]) => Math.abs(b ?? 0) - Math.abs(a ?? 0)) as [ScorePart, number][]
  if (parts.length === 0) return `Score ${formatScore(r.score)} – the radar's own match score, not a probability that you'll like it.`
  const lines = parts.map(([part, v]) => {
    const detail = scorePartDetail(r, part)
    return `${v < 0 ? '−' : '+'}${formatScore(Math.abs(v))}  ${SCORE_PART_LABELS[part]}${detail ? ` (${detail})` : ''}`
  })
  return [`Score ${formatScore(r.score)} adds up from:`, ...lines, 'A match score, not a probability that you’ll like it.'].join('\n')
}

const scoreFormat = new Intl.NumberFormat('da-DK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** "0,71" - the engine's score with two decimals (not a percentage). */
export function formatScore(score: number): string {
  return scoreFormat.format(score)
}

/** Deliberate bets on something new, in the engine's order. */
export function wildcardPicks(releases: Release[]): Release[] {
  return releases.filter((r) => r.pick.kind === 'wildcard').sort(byRank)
}

/** Genres that occur in the data, most common first ("no genre data" last). */
export function availableGenres(releases: Release[]): string[] {
  const count = new Map<string, number>()
  for (const r of releases) count.set(genreOf(r), (count.get(genreOf(r)) ?? 0) + 1)
  return [...count.keys()].sort(
    (a, b) => Number(a === NO_GENRE) - Number(b === NO_GENRE) || count.get(b)! - count.get(a)! || a.localeCompare(b),
  )
}

/**
 * One-line reason for a card: for a known artist, how you know them (from
 * your listening history - also when no genre data exists); otherwise the
 * strongest musical reason. Caveats are shown as "Limited data" instead.
 */
export function shortReason(r: Release): string {
  const known = r.reasons.find((x) => x.kind === 'known')
  if (known) return known.text
  return r.reasons.find((x) => x.kind !== 'caveat')?.text ?? ''
}

export function musicBrainzUrl(r: Release): string {
  return `https://musicbrainz.org/release-group/${r.id}`
}

/** The artist's page on Last.fm (credit for Last.fm data). */
export function lastfmUrl(r: Release): string {
  return `https://www.last.fm/music/${encodeURIComponent(r.artist).replace(/%20/g, '+')}`
}

/**
 * Front cover from the Cover Art Archive (linked to this exact MusicBrainz
 * release group), if one exists. One size everywhere, so the detail view
 * reuses the image the card already loaded.
 */
export function coverUrl(r: Release): string {
  return `https://coverartarchive.org/release-group/${r.id}/front-250`
}
