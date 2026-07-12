import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, onUnauthorized, setCsrfToken } from '@/api/client'

interface SessionState {
  status: 'loading' | 'authenticated' | 'anonymous'
  email: string | null
}

interface SessionContextValue extends SessionState {
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

const SessionContext = createContext<SessionContextValue | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<SessionState>({ status: 'loading', email: null })

  const refresh = useCallback(async () => {
    const res = await api.getSession()
    setCsrfToken(res.csrf_token)
    setState(
      res.authenticated
        ? { status: 'authenticated', email: res.email ?? null }
        : { status: 'anonymous', email: null },
    )
  }, [])

  useEffect(() => {
    // 401/403で未認証に落ちた後も再ログインできるよう、新しい匿名セッションの
    // CSRFトークンを取り直す(csrfTokenをnullにするだけだと以降のPOSTが全て弾かれる)。
    onUnauthorized(() => {
      void refresh()
    })
  }, [refresh])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const login = useCallback(async (email: string, password: string) => {
    const res = await api.login(email, password)
    setCsrfToken(res.csrf_token)
    setState({ status: 'authenticated', email: res.email })
  }, [])

  const logout = useCallback(async () => {
    await api.logout()
    // ログアウト後の新しい匿名セッション用CSRFトークンを取得し直す
    await refresh()
  }, [refresh])

  return (
    <SessionContext.Provider value={{ ...state, login, logout, refresh }}>
      {children}
    </SessionContext.Provider>
  )
}

export function useSession(): SessionContextValue {
  const ctx = useContext(SessionContext)
  if (!ctx) {
    throw new Error('useSession must be used within a SessionProvider')
  }
  return ctx
}
