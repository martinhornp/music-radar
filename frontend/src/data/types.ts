// Shape of GET /api/recommendations (written by api/bin/export-feed.php from a
// discovery run). Only verified data: anything not documented in MusicBrainz
// or Last.fm is null/empty here, never filled in.

export type ReleaseType = 'Album' | 'EP'

/** Where a pick comes from: an artist you follow, one from your listening history, or a new find. */
export type Origin = 'following' | 'interested' | 'discovery'

export type WildcardAngle = 'genre-blend' | 'new-band' | 'neighbouring-genre' | 'left-field' | 'known-artist' | 'limited-data'

export const WILDCARD_ANGLES: Record<WildcardAngle, string> = {
  'genre-blend': 'Genre blend',
  'new-band': 'New band',
  'neighbouring-genre': 'New territory',
  'left-field': 'Left-field',
  // Picked because you have listened to the artist, not for a strong match.
  'known-artist': 'Artist you know',
  // Too little music data for a recommendation, but a concrete reason to look.
  'limited-data': 'Limited data',
}

export const ORIGIN_LABELS: Record<Exclude<Origin, 'discovery'>, string> = {
  following: 'Following',
  interested: 'Interested',
}

/** Shown when a release has no documented genre at all. */
export const NO_GENRE = 'No genre data'

/**
 * Why a release is suggested.
 * - recommended: a match with the listener's taste (or a known artist)
 * - wildcard: a deliberate bet on something new, with a bridge back to the taste
 * - none: stored by the radar but not recommended (search results) - no reasons
 */
export interface Pick {
  kind: 'recommended' | 'wildcard' | 'none'
  angle: WildcardAngle | null
  /** Position in the engine's order (known artists first, then fit with variation). */
  rank: number
}

export interface Reason {
  /** known | listened | genres | tags | lastfm | rating | blend | novelty | danish | new_artist | caveat */
  kind: string
  text: string
}

/** The engine's signals that add up to a score (family/neighbours/blend: runs before model v2). */
export type ScorePart = 'genre' | 'lastfm' | 'family' | 'neighbours' | 'blend' | 'danish' | 'known' | 'outside' | 'rating'

export interface Release {
  /** MusicBrainz release-group id */
  id: string
  artist: string
  /** MusicBrainz artist id - lets a rating teach the engine about this artist */
  artistMbid?: string | null
  /** Reviewed MusicBrainz digital-edition link; absent for uncertain matches. */
  tidalAlbumId?: string | null
  title: string
  type: ReleaseType
  /** ISO date, yyyy-mm-dd (always a confirmed day) */
  releaseDate: string
  /** Genre family, null if no genre is documented */
  genre: string | null
  /** Documented genre tags, strongest first */
  tags: string[]
  /** Artist country (ISO code) if documented */
  country: string | null
  origin: Origin
  dataQuality: 'good' | 'limited'
  /**
   * The engine's own score (fit) from the current recommendations - a ranking
   * value, not a probability. Null when the current run did not assess the
   * release (search results; in My Music: outside the run's window).
   */
  score?: number | null
  /** What the score is made of: each signal's contribution (they add up to the score) */
  scoreParts?: Partial<Record<ScorePart, number>> | null
  pick: Pick
  reasons: Reason[]
  /** Where the facts behind the pick come from */
  sources: string[]
}

/** One release's stored feedback (GET /api/feedback), with the release as it was when marked. */
export interface FeedbackItem {
  releaseId: string
  rating: 1 | 2 | 3 | 4 | 5 | null
  notInterested: boolean
  saved: boolean
  updatedAt: string
  release: Release
}

/** A search result's place in the current recommendations. */
export type SearchStatus = 'recommended' | 'wildcard' | 'not_recommended'

export const SEARCH_STATUS_LABELS: Record<SearchStatus, string> = {
  recommended: 'Recommended for You',
  wildcard: 'Wildcard',
  not_recommended: 'Not recommended',
}

/** One of your most played albums (GET /api/top-albums) */
export interface TopAlbum {
  /** Last.fm plays, all time, at the one-time import */
  plays: number
  release: Release
}

/** GET /api/top-albums - most played first */
export interface TopAlbumsResponse {
  albums: TopAlbum[]
  total: number
}

/** GET /api/search */
export interface SearchResponse {
  query: string
  results: { status: SearchStatus; release: Release }[]
  /** More matches than shown - refine the search */
  more: boolean
  /** What the radar's database covers (for an honest "not found") */
  coverage: { releases: number; from: string | null; to: string | null }
}

export interface TasteProfile {
  genres: string[]
  artists: string[]
}

export interface Feed {
  generatedAt: string
  /** First and last release day covered */
  window: [string, string]
  /** Number of new releases the engine looked at */
  examined: number
  taste: TasteProfile
  releases: Release[]
}
