import { createContext, useContext } from 'react'
import { apiGet, apiPost } from '../api.ts'

// Login for the one user of Music Radar. The session is a server-side token
// in an HttpOnly cookie (30 days, until logout or a password change) - the
// browser never stores the password and scripts cannot read the session.

export interface User {
  username: string
}

export interface AuthState {
  status: 'loading' | 'ready'
  user: User | null
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
  changePassword: (current: string, next: string, confirm: string) => Promise<void>
}

export const AuthContext = createContext<AuthState>({
  status: 'loading',
  user: null,
  login: async () => {},
  logout: async () => {},
  changePassword: async () => {},
})

export function useAuth(): AuthState {
  return useContext(AuthContext)
}

export function useCurrentUser(): User | null {
  return useAuth().user
}

export async function fetchMe(signal?: AbortSignal): Promise<User | null> {
  return (await apiGet<{ user: User | null }>('/auth/me', signal)).user
}

export async function postLogin(username: string, password: string): Promise<User> {
  return (await apiPost<{ user: User }>('/auth/login', { username, password })).user
}

export async function postLogout(): Promise<void> {
  await apiPost('/auth/logout', {})
}

export async function postPassword(current: string, next: string, confirm: string): Promise<void> {
  await apiPost('/auth/password', { current, new: next, confirm })
}
