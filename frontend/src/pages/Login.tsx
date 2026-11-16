import { useEffect, useState, type FormEvent } from 'react'
import { setPageTitle } from '../lib/app.ts'
import { useAuth } from '../lib/auth.ts'
import { ROUTES, navigate } from '../lib/router.ts'

export function Login() {
  useEffect(() => setPageTitle('Log in'), [])
  const { user, login } = useAuth()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (user) navigate(ROUTES.discover)
  }, [user])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(username.trim(), password)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not log in')
      setPassword('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <div className="page-head">
        <p className="kicker">Account</p>
        <h1 className="page-title">Log in</h1>
      </div>
      <form className="panel form" onSubmit={(e) => void submit(e)}>
        <label className="form__field">
          <span>Username</span>
          <input autoComplete="username" value={username} onChange={(e) => setUsername(e.target.value)} required />
        </label>
        <label className="form__field">
          <span>Password</span>
          <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </label>
        {error && (
          <p className="form__error" role="alert">
            {error}
          </p>
        )}
        <button type="submit" className="btn" disabled={busy}>
          Log in
        </button>
      </form>
    </>
  )
}
