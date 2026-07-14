import { useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDownIcon, ChevronUpIcon, PaperclipIcon } from 'lucide-react'
import { api } from '@/api/client'
import type { ArticleListItem as ArticleListItemType } from '@/api/types'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { ArticleHeader } from '@/components/ArticleHeader'
import { ArticleBody } from '@/components/ArticleBody'
import { getErrorMessage } from '@/lib/errors'
import { cn } from '@/lib/utils'

interface ArticleCardProps {
  article: ArticleListItemType
  expanded: boolean
  onToggle: () => void
}

/**
 * 記事カード。折りたたみ/展開の2状態を持つ(DESIGN.md §6.2)。
 * 複数カードを同時に展開できる(排他にしない)。
 */
export function ArticleCard({ article, expanded, onToggle }: ArticleCardProps) {
  const containerRef = useRef<HTMLDivElement>(null)

  const query = useQuery({
    queryKey: ['article', article.id],
    queryFn: () => api.getArticle(article.id),
    enabled: expanded,
  })

  function handleClose() {
    onToggle()
    // 展開中に大きくスクロールしていた場合、カード先頭が画面内に収まるよう補正する
    requestAnimationFrame(() => {
      containerRef.current?.scrollIntoView({ block: 'nearest' })
    })
  }

  return (
    <div ref={containerRef}>
      <Card
        className={cn('gap-0 overflow-hidden py-0 transition-colors', expanded && 'ring-2 ring-ring')}
      >
        <button
          type="button"
          onClick={expanded ? handleClose : onToggle}
          aria-expanded={expanded}
          className="flex w-full flex-col gap-1 px-4 py-3 text-left text-sm hover:bg-muted/50"
        >
          <div className="flex items-center justify-between gap-2">
            <span className="truncate font-medium">{article.subject || '(件名なし)'}</span>
            <div className="flex shrink-0 items-center gap-2 text-muted-foreground">
              {article.has_attachments && <PaperclipIcon className="size-3.5" />}
              {expanded ? (
                <ChevronUpIcon className="size-4" />
              ) : (
                <ChevronDownIcon className="size-4" />
              )}
            </div>
          </div>
          <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
            <span className="truncate">{article.from_name || article.from_addr}</span>
            <span className="shrink-0">{formatDateLabel(article.date)}</span>
          </div>
          {!expanded && article.snippet && (
            <p className="line-clamp-1 text-xs text-muted-foreground">{article.snippet}</p>
          )}
        </button>

        {expanded && (
          <div className="border-t px-4 py-4">
            {query.isLoading && (
              <div className="flex flex-col gap-3">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-3/4" />
              </div>
            )}

            {query.isError && (
              <div>
                <Alert variant="destructive">
                  <AlertDescription>{getErrorMessage(query.error)}</AlertDescription>
                </Alert>
                <Button variant="outline" size="sm" className="mt-2" onClick={() => query.refetch()}>
                  再読み込み
                </Button>
              </div>
            )}

            {query.data && (
              <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                  <span>
                    {query.data.from_name
                      ? `${query.data.from_name} <${query.data.from_addr}>`
                      : query.data.from_addr}
                  </span>
                  <span>{formatDateTimeLabel(query.data.date)}</span>
                </div>
                <ArticleHeader article={query.data} />
                <ArticleBody text={query.data.body_text} />
                <div className="flex justify-end border-t pt-3">
                  <Button variant="outline" size="sm" onClick={handleClose}>
                    閉じる
                  </Button>
                </div>
              </div>
            )}
          </div>
        )}
      </Card>
    </div>
  )
}

function formatDateLabel(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) {
    return ''
  }
  return d.toLocaleDateString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit' })
}

/** 展開時のみ使用。折りたたみ時の日付表示(formatDateLabel)と異なり時刻まで表示する。 */
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
