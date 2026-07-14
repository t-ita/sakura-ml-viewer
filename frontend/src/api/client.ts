import type {
  AdminArticlesResponse,
  AdminArticleSearchParams,
  AdminUsersResponse,
  ArticleDetail,
  ArticleListResponse,
  ArticleSearchParams,
  IndexStatus,
  LoginResponse,
  MessageResponse,
  SessionResponse,
} from './types'

// index.htmlからの相対パス。デプロイ先ディレクトリ名(例: ml-viewer)に依存しない。
const API_BASE = 'api'

export class ApiError extends Error {
  readonly code: string
  readonly status: number

  constructor(code: string, message: string, status: number) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.status = status
  }
}

let csrfToken: string | null = null

export function setCsrfToken(token: string | null): void {
  csrfToken = token
}

type UnauthorizedListener = () => void
let unauthorizedListener: UnauthorizedListener | null = null

/** SessionProviderが自身を登録し、401/403応答を受けたら未認証状態へ戻す。 */
export function onUnauthorized(listener: UnauthorizedListener): void {
  unauthorizedListener = listener
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const method = init?.method ?? 'GET'
  const headers: Record<string, string> = {}
  if (init?.body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  if (method !== 'GET' && csrfToken) {
    headers['X-CSRF-Token'] = csrfToken
  }

  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    method,
    credentials: 'same-origin',
    headers: { ...headers, ...init?.headers },
  })

  const text = await res.text()
  const data: unknown = text ? JSON.parse(text) : {}

  if (!res.ok) {
    const body = data as { error?: { code?: string; message?: string } }
    const code = body.error?.code ?? 'unknown_error'
    const message = body.error?.message ?? 'エラーが発生しました。しばらくしてから再度お試しください。'

    if (res.status === 401 || res.status === 403) {
      unauthorizedListener?.()
    }

    throw new ApiError(code, message, res.status)
  }

  return data as T
}

function toQueryString(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  }
  const qs = search.toString()
  return qs ? `?${qs}` : ''
}

export const api = {
  getSession(): Promise<SessionResponse> {
    return request<SessionResponse>('/auth/session')
  },

  login(email: string, password: string): Promise<LoginResponse> {
    return request<LoginResponse>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
  },

  logout(): Promise<Record<string, never>> {
    return request('/auth/logout', { method: 'POST' })
  },

  requestToken(email: string): Promise<MessageResponse> {
    return request<MessageResponse>('/auth/request-token', {
      method: 'POST',
      body: JSON.stringify({ email }),
    })
  },

  setPassword(token: string, password: string): Promise<Record<string, never>> {
    return request('/auth/set-password', {
      method: 'POST',
      body: JSON.stringify({ token, password }),
    })
  },

  changePassword(currentPassword: string, newPassword: string): Promise<Record<string, never>> {
    return request('/auth/change-password', {
      method: 'POST',
      body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
    })
  },

  listArticles(params: ArticleSearchParams): Promise<ArticleListResponse> {
    return request<ArticleListResponse>(`/articles${toQueryString({ ...params })}`)
  },

  getArticle(id: number): Promise<ArticleDetail> {
    return request<ArticleDetail>(`/articles/${id}`)
  },

  getIndexStatus(): Promise<IndexStatus> {
    return request<IndexStatus>('/index/status')
  },

  getAdminUsers(): Promise<AdminUsersResponse> {
    return request<AdminUsersResponse>('/admin/users')
  },

  listAdminArticles(params: AdminArticleSearchParams): Promise<AdminArticlesResponse> {
    return request<AdminArticlesResponse>(`/admin/articles${toQueryString({ ...params })}`)
  },
}
