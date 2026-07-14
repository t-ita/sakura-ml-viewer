// DESIGN.md §4 のAPIレスポンスJSONスキーマに対応する型定義。

export interface SessionResponse {
  authenticated: boolean
  email?: string
  csrf_token: string
  is_admin?: boolean
}

export interface LoginResponse {
  email: string
  csrf_token: string
  is_admin: boolean
}

export interface MessageResponse {
  message: string
}

export interface ArticleListItem {
  id: number
  subject: string
  from_name: string
  from_addr: string
  date: string
  has_attachments: boolean
  snippet: string
}

export interface ArticleListResponse {
  items: ArticleListItem[]
  total: number
  page: number
  per_page: number
  indexing: {
    pending: number
  }
}

export interface ArticleAttachment {
  filename: string
  mime: string
  size: number
}

export type ParseStatus = 'ok' | 'partial' | 'error'

export interface ArticleDetail {
  id: number
  subject: string
  from_name: string
  from_addr: string
  date: string
  message_id: string | null
  body_text: string
  attachments: ArticleAttachment[]
  parse_status: ParseStatus
}

export interface IndexStatus {
  indexed_max: number
  seq: number
  pending: number
}

export interface ArticleSearchParams {
  page?: number
  per_page?: number
  q?: string
  sender?: string
  date_from?: string
  date_to?: string
  id_from?: number
  id_to?: number
}

// 管理者機能（改訂3）。DESIGN.md §4.5 に対応する。

export interface AdminUserItem {
  email: string
  password_registered: boolean
  created_at: string | null
  password_updated_at: string | null
  last_login_at: string | null
  pending_token: boolean
}

export interface AdminUsersResponse {
  summary: {
    active_members: number
    password_registered: number
    orphan_users: number
  }
  members: AdminUserItem[]
  orphan_users: AdminUserItem[]
}

export type AdminArticleStatus = 'all' | 'ok' | 'partial' | 'error'

export interface AdminArticleItem {
  id: number
  subject: string
  from_addr: string
  date: string | null
  parse_status: ParseStatus
  indexed_at: string
}

export interface AdminArticlesResponse {
  summary: {
    seq: number
    indexed_max: number
    pending: number
    count_ok: number
    count_partial: number
    count_error: number
  }
  items: AdminArticleItem[]
  total: number
  page: number
  per_page: number
}

export interface AdminArticleSearchParams {
  page?: number
  per_page?: number
  status?: AdminArticleStatus
}
