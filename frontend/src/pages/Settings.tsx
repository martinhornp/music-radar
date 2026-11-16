import { useEffect, useState, type FormEvent } from 'react'
import { SERVICE_STATUS_LABEL, getServices } from '../data/services.ts'
import { setPageTitle } from '../lib/app.ts'
import { useAuth } from '../lib/auth.ts'
import { SUPPORTED_TYPES } from '../lib/releases.ts'
import { ROUTES, navigate } from '../lib/router.ts'

// Settings - only when logged in (the password change is checked by the API
// too). The rest is still a preview of what will be configurable.

function PasswordForm() {
  const { changePassword } = useAuth()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setMessage(null)
    try {
      await changePassword(current, next, confirm)
      setMessage({ ok: true, text: 'Password changed. Other devices have been logged out.' })
      setCurrent('')
      setNext('')
      setConfirm('')
    } catch (err) {
      setMessage({ ok: false, text: err instanceof Error ? err.message : 'Could not change the password' })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="form" onSubmit={(e) => void submit(e)}>
      <label className="form__field">
        <span>Current password</span>
        <input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} required />
      </label>
      <label className="form__field">
        <span>New password</span>
        <input type="password" autoComplete="new-password" minLength={8} value={next} onChange={(e) => setNext(e.target.value)} required />
      </label>
      <label className="form__field">
        <span>Confirm new password</span>
        <input type="password" autoComplete="new-password" minLength={8} value={confirm} onChange={(e) => setConfirm(e.target.value)} required />
      </label>
      {message && (
        <p className={message.ok ? 'form__ok' : 'form__error'} role={message.ok ? 'status' : 'alert'}>
          {message.text}
        </p>
      )}
      <button type="submit" className="btn" disabled={busy}>
        Save
      </button>
    </form>
  )
}

export function Settings() {
  useEffect(() => setPageTitle('Settings'), [])
  const { status, user } = useAuth()
  const services = getServices()

  useEffect(() => {
    if (status === 'ready' && !user) navigate(ROUTES.login)
  }, [status, user])
  if (!user) {
    return null
  }

  return (
    <>
      <div className="page-head">
        <p className="kicker">Settings</p>
        <h1 className="page-title">Settings</h1>
        <p className="page-lead">Your account, and a preview of what you'll be able to configure.</p>
      </div>

      <div className="settings">
        <section className="panel" aria-labelledby="s-account">
          <h2 id="s-account" className="panel__title">
            Account
          </h2>
          <p className="panel__text">Logged in as {user.username}. You stay logged in for 30 days on this device, unless you log out.</p>
        </section>

        <section className="panel" aria-labelledby="s-password">
          <h2 id="s-password" className="panel__title">
            Password
          </h2>
          <PasswordForm />
        </section>

        <section className="panel" aria-labelledby="s-services">
          <h2 id="s-services" className="panel__title">
            Connected services
          </h2>
          <ul className="rows">
            {services.map((s) => (
              <li key={s.id} className="row">
                <div>
                  <p className="row__name">{s.name}</p>
                  <p className="row__desc">{s.purpose}</p>
                  {s.account && <p className="row__account">User: {s.account}</p>}
                </div>
                <span className={`status-pill status-pill--${s.status}`}>{SERVICE_STATUS_LABEL[s.status]}</span>
              </li>
            ))}
          </ul>
        </section>

        <section className="panel" aria-labelledby="s-discovery">
          <h2 id="s-discovery" className="panel__title">
            Discovery
          </h2>
          <ul className="rows">
            {SUPPORTED_TYPES.map((t) => (
              <li key={t} className="row">
                <p className="row__name">{t === 'Album' ? 'Albums' : 'EPs'}</p>
                <span className="status-pill status-pill--on">Included</span>
              </li>
            ))}
            <li className="row">
              <div>
                <p className="row__name">Wildcards</p>
                <p className="row__desc">Less obvious picks outside your usual taste</p>
              </div>
              <span className="status-pill status-pill--on">On</span>
            </li>
            <li className="row">
              <div>
                <p className="row__name">Singles</p>
                <p className="row__desc">Not part of this version</p>
              </div>
              <span className="status-pill">Off</span>
            </li>
          </ul>
        </section>

        <section className="panel" aria-labelledby="s-appearance">
          <h2 id="s-appearance" className="panel__title">
            Appearance
          </h2>
          <ul className="rows">
            <li className="row">
              <p className="row__name">Theme</p>
              <span className="status-pill status-pill--on">Dark</span>
            </li>
          </ul>
        </section>
      </div>
    </>
  )
}
