import { PaperclipIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { ArticleListItem as ArticleListItemType } from '@/api/types'

interface ArticleListItemProps {
  article: ArticleListItemType
  selected: boolean
  onSelect: () => void
}

export function ArticleListItem({ article, selected, onSelect }: ArticleListItemProps) {
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-current={selected}
      className={cn(
        'flex w-full flex-col gap-1 border-b px-3 py-2.5 text-left text-sm transition-colors hover:bg-muted',
        selected && 'bg-accent hover:bg-accent',
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="truncate font-medium">{article.subject || '(件名なし)'}</span>
        {article.has_attachments && (
          <PaperclipIcon className="size-3.5 shrink-0 text-muted-foreground" />
        )}
      </div>
      <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="truncate">{article.from_name || article.from_addr}</span>
        <span className="shrink-0">{formatDateLabel(article.date)}</span>
      </div>
      {article.snippet && (
        <p className="line-clamp-1 text-xs text-muted-foreground">{article.snippet}</p>
      )}
    </button>
  )
}

function formatDateLabel(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) {
    return ''
  }
  return d.toLocaleDateString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit' })
}
