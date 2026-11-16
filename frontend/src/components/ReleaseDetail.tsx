import { useEffect, useRef } from 'react'
import { WILDCARD_ANGLES, type Release } from '../data/types.ts'
import { useCurrentUser } from '../lib/auth.ts'
import { useFeedback } from '../lib/feedback.ts'
import { countryName, formatDate } from '../lib/format.ts'
import { formatScore, genreOf, lastfmUrl, musicBrainzUrl, scoreExplanation } from '../lib/releases.ts'
import { Cover } from './Cover.tsx'
import { BanIcon, BookmarkIcon, CloseIcon, DiceIcon, SparkIcon } from './Icons.tsx'
import { ReleaseFlags } from './ReleaseFlags.tsx'
import { StarInput } from './StarRating.tsx'
import { TidalLink } from './TidalLink.tsx'

interface Props {
  release: Release | null
  onClose: () => void
}

/** Release detail as a native modal <dialog> (focus trap + Esc for free). */
export function ReleaseDetail({ release, onClose }: Props) {
  const ref = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (release && !dialog.open) dialog.showModal()
    if (!release && dialog.open) dialog.close()
  }, [release])

  return (
    <dialog
      ref={ref}
      className="detail"
      aria-labelledby="detail-title"
      onClose={onClose}
      onClick={(e) => {
        // Click on the backdrop (outside the panel) closes the dialog.
        if (e.target === e.currentTarget) onClose()
      }}
    >
      {release && <DetailBody release={release} onClose={onClose} />}
    </dialog>
  )
}

function DetailBody({ release, onClose }: { release: Release; onClose: () => void }) {
  const feedback = useFeedback()
  const f = feedback.get(release.id)
  // Rating and marking need the login; visitors who are not logged in have no use for them.
  const user = useCurrentUser()
  const { pick } = release
  const reasons = release.reasons.filter((r) => r.kind !== 'caveat')
  const caveats = release.reasons.filter((r) => r.kind === 'caveat')

  return (
    <div className="detail__panel">
      <button type="button" className="icon-btn detail__close" onClick={onClose} aria-label="Close">
        <CloseIcon />
      </button>

      <div className="detail__cover">
        <Cover release={release} />
      </div>

      <div className="detail__content">
        <p className="detail__kicker">
          <span className={`type type--${release.type.toLowerCase()}`}>{release.type}</span>
          {genreOf(release)}
        </p>
        <h2 id="detail-title" className="detail__title">
          {release.title}
        </h2>
        <p className="detail__artist">{release.artist}</p>
        <ReleaseFlags release={release} />
        <TidalLink release={release} />

        <dl className="facts">
          <div>
            <dt>Released</dt>
            <dd>
              <time dateTime={release.releaseDate}>{formatDate(release.releaseDate)}</time>
            </dd>
          </div>
          {release.score != null && (
            <div>
              <dt>Score</dt>
              <dd title={scoreExplanation(release)}>{formatScore(release.score)}</dd>
            </div>
          )}
          {release.country && (
            <div>
              <dt>Artist from</dt>
              <dd>{countryName(release.country)}</dd>
            </div>
          )}
          <div>
            <dt>Data from</dt>
            <dd>
              {release.sources.join(' + ') || 'MusicBrainz'} ·{' '}
              <a href={musicBrainzUrl(release)} target="_blank" rel="noreferrer">
                MusicBrainz page
              </a>
              {release.sources.includes('Last.fm') && (
                <>
                  {' · '}
                  <a href={lastfmUrl(release)} target="_blank" rel="noreferrer">
                    Last.fm page
                  </a>
                </>
              )}
            </dd>
          </div>
        </dl>

        {release.tags.length > 0 && (
          <ul className="tags" aria-label="Genre tags">
            {release.tags.map((t) => (
              <li key={t}>{t}</li>
            ))}
          </ul>
        )}

        {pick.kind === 'none' ? (
          <section className="why why--none" aria-label="Not recommended">
            <div className="why__head">
              <span>Not recommended</span>
            </div>
            <p>The radar has this release in its database but did not pick it for your recommendations.</p>
          </section>
        ) : pick.kind === 'recommended' ? (
          <section className="why" aria-label="Why it's recommended">
            <div className="why__head">
              <SparkIcon width="16" height="16" />
              <span>Recommended for you</span>
            </div>
            <Reasons texts={reasons.map((r) => r.text)} caveats={caveats.map((r) => r.text)} />
          </section>
        ) : (
          <section className="why why--wildcard" aria-label="Why this wildcard">
            <div className="why__head">
              <DiceIcon width="16" height="16" />
              <span>Wildcard{pick.angle && ` · ${WILDCARD_ANGLES[pick.angle]}`}</span>
            </div>
            <Reasons texts={reasons.map((r) => r.text)} caveats={caveats.map((r) => r.text)} />
          </section>
        )}

        {user && (
          <section className="feedback" aria-label="Your feedback">
            <div className="feedback__rating">
              <p className="feedback__label">
                Your rating <span>after listening</span>
              </p>
              <StarInput value={f.rating} onChange={(s) => feedback.rate(release, s)} />
            </div>

            <div className="actions">
              <button
                type="button"
                className="action action--saved"
                aria-pressed={f.saved}
                onClick={() => feedback.toggleSaved(release)}
              >
                <BookmarkIcon width="18" height="18" /> {f.saved ? 'Saved for later' : 'Save for later'}
              </button>
              <button
                type="button"
                className="action action--nope"
                aria-pressed={f.notInterested}
                onClick={() => feedback.toggleNotInterested(release)}
              >
                <BanIcon width="18" height="18" /> Not interested
              </button>
            </div>
          </section>
        )}
      </div>
    </div>
  )
}

function Reasons({ texts, caveats }: { texts: string[]; caveats: string[] }) {
  return (
    <>
      {texts.length > 0 ? (
        <ul className="why__list">
          {texts.map((t) => (
            <li key={t}>{t}</li>
          ))}
        </ul>
      ) : (
        <p>No genre information is documented yet, so there is no musical reason to show.</p>
      )}
      {caveats.length > 0 && (
        <ul className="why__caveats" aria-label="Data limitations">
          {caveats.map((t) => (
            <li key={t}>{t}</li>
          ))}
        </ul>
      )}
    </>
  )
}
