import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { AuthContext, fetchMe, postLogin, postLogout, postPassword, type AuthState, type User } from '../lib/auth.ts'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [status, setStatus] = useState<AuthState['status']>('loading')

  useEffect(() => {
    const ctrl = new AbortController()
    fetchMe(ctrl.signal)
      .then((u) => {
        setUser(u)
        setStatus('ready')
      })
      .catch(() => {
        // Login unavailable: behave as logged out.
        if (!ctrl.signal.aborted) setStatus('ready')
      })
    return () => ctrl.abort()
  }, [])

  const login = useCallback(async (username: string, password: string) => {
    setUser(await postLogin(username, password))
  }, [])
  const logout = useCallback(async () => {
    await postLogout()
    setUser(null)
  }, [])
  const changePassword = useCallback(async (current: string, next: string, confirm: string) => {
    await postPassword(current, next, confirm)
  }, [])

  const value = useMemo<AuthState>(() => ({ status, user, login, logout, changePassword }), [status, user, login, logout, changePassword])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
