import { PaperclipIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import type { ArticleDetail as ArticleDetailType } from '@/api/types'

interface ArticleHeaderProps {
  article: ArticleDetailType
}

/**
 * 件名・送信者・日付はカードの折りたたみ時ヘッダー(ArticleCard)に既に表示されているため、
 * ここではparse_status警告と添付一覧のみを表示する(DESIGN.md §6.4)。
 */
export function ArticleHeader({ article }: ArticleHeaderProps) {
  if (article.parse_status !== 'partial' && article.attachments.length === 0) {
    return null
  }

  return (
    <div className="flex flex-col gap-2 border-b pb-4">
      {article.parse_status === 'partial' && (
        <p className="text-xs text-amber-600 dark:text-amber-500">
          ※ このメールは一部を正しく解析できませんでした。表示内容が不完全な場合があります。
        </p>
      )}
      {article.attachments.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
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

function formatSize(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes}B`
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)}KB`
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)}MB`
}
