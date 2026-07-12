// DESIGN.md §4 のAPIレスポンスJSONスキーマに対応する型定義。

export interface SessionResponse {
  authenticated: boolean
  email?: string
  csrf_token: string
}

export interface LoginResponse {
  email: string
  csrf_token: string
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
}
