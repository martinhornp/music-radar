import { useEffect } from 'react'
import { setPageTitle } from '../lib/app.ts'
import { ROUTES, navigate } from '../lib/router.ts'

export function NotFound() {
  useEffect(() => setPageTitle('Not found'), [])

  return (
    <div className="page-head page-head--center">
      <p className="kicker">404</p>
      <h1 className="page-title">Nothing on the radar here</h1>
      <p className="page-lead">This page doesn't exist.</p>
      <button type="button" className="btn" onClick={() => navigate(ROUTES.discover)}>
        Back to Discover
      </button>
    </div>
  )
}
