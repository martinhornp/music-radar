import { useEffect } from 'react'
import { Cover } from '../components/Cover.tsx'
import { StarInput } from '../components/StarRating.tsx'
import type { TopAlbum } from '../data/types.ts'
import { setPageTitle } from '../lib/app.ts'
import { useAuth } from '../lib/auth.ts'
import { useFeedback } from '../lib/feedback.ts'
import { ROUTES, navigate } from '../lib/router.ts'
import { useTopAlbums } from '../lib/topAlbums.ts'

// "Bedøm albums" - only when logged in. Your most played albums (imported once
// from Last.fm) that you have not rated yet, most played first. A rating is
// ordinary feedback: the album leaves the list at once and the engine learns
// from it at the next import. Ratings you already gave are never shown here.

export function RateAlbums() {
  useEffect(() => setPageTitle('Bedøm albums'), [])
  const { status, user } = useAuth()
  const state = useTopAlbums()

  useEffect(() => {
    if (status === 'ready' && !user) navigate(ROUTES.login)
  }, [status, user])

  if (!user) {
    return null
  }

  return (
    <>
      <div className="page-head">
        <p className="kicker">Bedøm albums</p>
        <h1 className="page-title">Bedøm albums</h1>
        <p className="page-lead">Dine mest spillede albums på Last.fm. Hver karakter lærer Music Radar din smag bedre at kende.</p>
      </div>
      {state.status === 'ready' ? (
        <AlbumList albums={state.albums} />
      ) : (
        <p className="empty">{state.status === 'loading' ? 'Henter albums …' : 'Albumlisten kunne ikke hentes lige nu.'}</p>
      )}
    </>
  )
}

function AlbumList({ albums }: { albums: TopAlbum[] }) {
  const feedback = useFeedback()

  if (feedback.status === 'loading') {
    return <p className="empty">Henter dine karakterer …</p>
  }
  if (albums.length === 0) {
    return <p className="empty">Albumlisten er ikke importeret endnu.</p>
  }
  const todo = albums.filter((a) => feedback.get(a.release.id).rating === null)
  const rated = albums.length - todo.length

  return (
    <section aria-labelledby="rate-progress">
      <p id="rate-progress" className="section__sub rate-progress">
        {rated} af {albums.length} bedømt
      </p>
      {todo.length === 0 ? (
        <p className="empty">Alle albums er bedømt.</p>
      ) : (
        <ul className="rate-list">
          {todo.map(({ plays, release }) => (
            <li key={release.id} className="rate-row">
              <span className="rate-row__cover">
                <Cover release={release} />
              </span>
              <div className="rate-row__text">
                <p className="rate-row__title">{release.title}</p>
                <p className="rate-row__artist">{release.artist}</p>
                <p className="rate-row__plays">{plays.toLocaleString('da-DK')} afspilninger</p>
              </div>
              <StarInput value={null} onChange={(s) => feedback.rate(release, s)} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
