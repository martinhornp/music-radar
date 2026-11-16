import type { MouseEvent, ReactNode } from 'react'
import { APP_NAME } from '../lib/app.ts'
import { useAuth } from '../lib/auth.ts'
import { ROUTES, navigate, usePath } from '../lib/router.ts'
import { useUnratedAlbums } from '../lib/topAlbums.ts'
import { DiscIcon, RadarIcon, SlidersIcon, StarIcon, UserIcon } from './Icons.tsx'

const NAV: { path: string; label: string; icon: ReactNode; loggedIn?: boolean }[] = [
  { path: ROUTES.discover, label: 'Discover', icon: <RadarIcon /> },
  { path: ROUTES.myMusic, label: 'Martins ratings', icon: <DiscIcon /> },
  { path: ROUTES.rateAlbums, label: 'Bedøm albums', icon: <StarIcon />, loggedIn: true },
  { path: ROUTES.settings, label: 'Settings', icon: <SlidersIcon />, loggedIn: true },
]

function onNav(e: MouseEvent<HTMLAnchorElement>, path: string) {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
  e.preventDefault()
  navigate(path)
}

export function Header() {
  const path = usePath()
  const { user, logout } = useAuth()
  // "Bedøm albums" only while there is something left to rate.
  const unrated = useUnratedAlbums(user !== null)
  const hidden = (path: string) => path === ROUTES.rateAlbums && !unrated?.length

  return (
    <header className="topbar">
      <div className="topbar__inner">
        <a href={ROUTES.discover} className="brand" aria-label={`${APP_NAME} – Discover`}>
          <span className="brand__mark" aria-hidden="true">
            <RadarIcon width="22" height="22" />
          </span>
          <span className="brand__name">
            <span className="brand__owner">Martins</span> Music Radar
          </span>
        </a>

        <nav className="nav" aria-label="Main">
          {NAV.filter((item) => (!item.loggedIn || user) && !hidden(item.path)).map((item) => (
            <a
              key={item.path}
              href={item.path}
              className="nav__link"
              aria-current={path === item.path ? 'page' : undefined}
              onClick={(e) => onNav(e, item.path)}
            >
              {item.icon}
              <span>{item.label}</span>
            </a>
          ))}
        </nav>

        {user ? (
          <button type="button" className="profile" title={`Logged in as ${user.username}`} onClick={() => void logout().then(() => navigate(ROUTES.discover))}>
            <UserIcon width="18" height="18" />
            <span className="profile__label">Log out</span>
          </button>
        ) : (
          <a href={ROUTES.login} className="profile" onClick={(e) => onNav(e, ROUTES.login)}>
            <UserIcon width="18" height="18" />
            <span className="profile__label">Log in</span>
          </a>
        )}
      </div>
    </header>
  )
}
