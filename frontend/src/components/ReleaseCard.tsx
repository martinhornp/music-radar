import { WILDCARD_ANGLES, type Release, type SearchStatus } from '../data/types.ts'
import { useFeedback, type ReleaseFeedback } from '../lib/feedback.ts'
import { formatDate } from '../lib/format.ts'
import { formatScore, genreOf, scoreExplanation, shortReason } from '../lib/releases.ts'
import { Cover } from './Cover.tsx'
import { BanIcon, BookmarkIcon } from './Icons.tsx'
import { ReleaseFlags } from './ReleaseFlags.tsx'
import { StarDisplay } from './StarRating.tsx'
import { TidalLink } from './TidalLink.tsx'

export type CardVariant = 'recommended' | 'wildcard' | 'plain'

interface Props {
  release: Release
  variant?: CardVariant
  /** Search results: where the release stands in the recommendations */
  status?: SearchStatus
  onOpen: (release: Release) => void
}

export function ReleaseCard({ release, variant = 'plain', status, onOpen }: Props) {
  const feedback = useFeedback().get(release.id)
  const { pick } = release
  const reason = shortReason(release)

  return (
    <article className={`card card--${variant}${feedback.notInterested ? ' is-dismissed' : ''}`}>
      <button type="button" className="card__hit" onClick={() => onOpen(release)}>
        <span className="sr-only">
          Open {release.title} by {release.artist}
        </span>
      </button>

      <div className="card__cover">
        <Cover release={release} />
        {variant === 'wildcard' && pick.angle && <span className="angle">{WILDCARD_ANGLES[pick.angle]}</span>}
        <FeedbackMarks feedback={feedback} />
      </div>

      <div className="card__body">
        <ReleaseFlags release={release} status={status} />
        <p className="card__artist">{release.artist}</p>
        <h3 className="card__title">{release.title}</h3>
        <p className="card__meta">
          <span className={`type type--${release.type.toLowerCase()}`}>{release.type}</span>
          <span>{genreOf(release)}</span>
          {release.tags[0] && <span className="card__tag">· {release.tags[0]}</span>}
        </p>
        <p className="card__date">
          <time dateTime={release.releaseDate}>{formatDate(release.releaseDate)}</time>
          {variant === 'recommended' && release.score != null && (
            <span className="card__score" title={scoreExplanation(release)}>
              {' '}
              · Score {formatScore(release.score)}
            </span>
          )}
        </p>

        {feedback.rating && (
          <p className="card__rating">
            <StarDisplay value={feedback.rating} />
          </p>
        )}

        {variant !== 'plain' && reason && <p className={`card__reason card__reason--${pick.kind}`}>{reason}</p>}
        <TidalLink release={release} />
      </div>
    </article>
  )
}

function FeedbackMarks({ feedback }: { feedback: ReleaseFeedback }) {
  if (!feedback.saved && !feedback.notInterested) return null
  return (
    <span className="marks">
      {feedback.notInterested && (
        <span className="mark mark--nope" title="Not interested">
          <BanIcon width="14" height="14" />
        </span>
      )}
      {feedback.saved && (
        <span className="mark mark--saved" title="Saved for later">
          <BookmarkIcon width="14" height="14" />
        </span>
      )}
    </span>
  )
}
