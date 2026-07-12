import { PaperclipIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import type { ArticleDetail as ArticleDetailType } from '@/api/types'

interface ArticleHeaderProps {
  article: ArticleDetailType
}

export function ArticleHeader({ article }: ArticleHeaderProps) {
  return (
    <div className="flex flex-col gap-2 border-b pb-4">
      <h1 className="text-lg font-semibold break-words">{article.subject || '(件名なし)'}</h1>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
        <span>
          {article.from_name ? `${article.from_name} <${article.from_addr}>` : article.from_addr}
        </span>
        <span>{formatDateTimeLabel(article.date)}</span>
      </div>
      {article.parse_status === 'partial' && (
        <p className="text-xs text-amber-600 dark:text-amber-500">
          ※ このメールは一部を正しく解析できませんでした。表示内容が不完全な場合があります。
        </p>
      )}
      {article.attachments.length > 0 && (
        <div className="flex flex-wrap gap-1.5 pt-1">
          {article.attachments.map((att, i) => (
            <Badge key={i} variant="outline" className="gap-1 font-normal">
              <PaperclipIcon className="size-3" />
              {att.filename}
              <span className="text-muted-foreground">({formatSize(att.size)})</span>
            </Badge>
          ))}
        </div>
      )}
    </div>
  )
}

function formatDateTimeLabel(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) {
    return ''
  }
  return d.toLocaleString('ja-JP', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatSize(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes}B`
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)}KB`
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)}MB`
}
