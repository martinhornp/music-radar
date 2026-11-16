import { ORIGIN_LABELS, SEARCH_STATUS_LABELS, type Release, type SearchStatus } from '../data/types.ts'

/**
 * "Following" / "Interested" and "Limited data" markers, plus - for search
 * results - the release's status in the recommendations. Renders nothing for
 * a plain new find.
 */
export function ReleaseFlags({ release, status }: { release: Release; status?: SearchStatus }) {
  const known = release.origin !== 'discovery'
  const limited = release.dataQuality === 'limited'
  if (!known && !limited && !status) return null

  return (
    <p className="flags">
      {status && <span className={`flag flag--status flag--${status}`}>{SEARCH_STATUS_LABELS[status]}</span>}
      {release.origin !== 'discovery' && (
        <span
          className={`flag flag--${release.origin}`}
          title={release.origin === 'following' ? 'You follow this artist' : 'An artist from your listening history'}
        >
          {ORIGIN_LABELS[release.origin]}
        </span>
      )}
      {limited && (
        <span className="flag flag--limited" title="Little genre information is documented for this release">
          Limited data
        </span>
      )}
    </p>
  )
}
