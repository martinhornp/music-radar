import { AuthProvider } from './components/AuthProvider.tsx'
import { FeedbackNotice } from './components/FeedbackNotice.tsx'
import { FeedbackProvider } from './components/FeedbackProvider.tsx'
import { FeedProvider } from './components/FeedProvider.tsx'
import { Header } from './components/Header.tsx'
import { Discover } from './pages/Discover.tsx'
import { Login } from './pages/Login.tsx'
import { MyMusic } from './pages/MyMusic.tsx'
import { NotFound } from './pages/NotFound.tsx'
import { RateAlbums } from './pages/RateAlbums.tsx'
import { Settings } from './pages/Settings.tsx'
import { useEffect } from 'react'
import { MOVED, ROUTES, redirect, usePath } from './lib/router.ts'

function Moved({ to }: { to: string }) {
  useEffect(() => redirect(to), [to])
  return null
}

function Page({ path }: { path: string }) {
  const route = path.replace(/\/+$/, '') || '/'
  if (MOVED[route]) {
    return <Moved to={MOVED[route]} />
  }
  switch (route) {
    case ROUTES.discover:
      return <Discover />
    case ROUTES.myMusic:
      return <MyMusic />
    case ROUTES.rateAlbums:
      return <RateAlbums />
    case ROUTES.settings:
      return <Settings />
    case ROUTES.login:
      return <Login />
    default:
      return <NotFound />
  }
}

function App() {
  const path = usePath()

  return (
    <AuthProvider>
      <FeedProvider>
      <FeedbackProvider>
        <a className="skip-link" href="#main">
          Skip to content
        </a>
        <Header />
        <main id="main" className="main">
          <Page path={path} />
        </main>
        <FeedbackNotice />
      </FeedbackProvider>
      </FeedProvider>
    </AuthProvider>
  )
}

export default App
