import { useInfiniteQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { ArticleSearchParams } from '@/api/types'
import { Skeleton } from '@/components/ui/skeleton'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { ArticleCard } from '@/components/ArticleCard'
import { getErrorMessage } from '@/lib/errors'

const PER_PAGE = 50

interface ArticleCardListProps {
  filters: ArticleSearchParams
  expandedIds: Set<number>
  onToggle: (id: number) => void
  pollWhileIndexing: boolean
}

export function ArticleCardList({
  filters,
  expandedIds,
  onToggle,
  pollWhileIndexing,
}: ArticleCardListProps) {
  const query = useInfiniteQuery({
    queryKey: ['articles', filters],
    queryFn: ({ pageParam }) => api.listArticles({ ...filters, page: pageParam, per_page: PER_PAGE }),
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      const loaded = lastPage.page * lastPage.per_page
      return loaded < lastPage.total ? lastPage.page + 1 : undefined
    },
    refetchInterval: pollWhileIndexing ? 5000 : false,
  })

  if (query.isLoading) {
    return (
      <div className="mx-auto flex max-w-2xl flex-col gap-3 p-4">
        {Array.from({ length: 10 }).map((_, i) => (
          <Skeleton key={i} className="h-20 w-full" />
        ))}
      </div>
    )
  }

  if (query.isError) {
    return (
      <div className="mx-auto max-w-2xl p-4">
        <Alert variant="destructive">
          <AlertDescription>{getErrorMessage(query.error)}</AlertDescription>
        </Alert>
        <Button variant="outline" size="sm" className="mt-2" onClick={() => query.refetch()}>
          再読み込み
        </Button>
      </div>
    )
  }

  if (!query.data) {
    return null
  }

  const items = query.data.pages.flatMap((p) => p.items)
  const total = query.data.pages[0]?.total ?? 0

  if (items.length === 0) {
    return (
      <div className="p-10 text-center text-sm text-muted-foreground">
        条件に一致する記事がありません。
      </div>
    )
  }

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-3 p-4">
      {items.map((article) => (
        <ArticleCard
          key={article.id}
          article={article}
          expanded={expandedIds.has(article.id)}
          onToggle={() => onToggle(article.id)}
        />
      ))}
      <div className="flex flex-col items-center gap-2 py-3">
        <p className="text-xs text-muted-foreground">
          {items.length} / {total} 件
        </p>
        {query.hasNextPage && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => query.fetchNextPage()}
            disabled={query.isFetchingNextPage}
          >
            {query.isFetchingNextPage ? '読み込み中...' : 'さらに読み込む'}
          </Button>
        )}
      </div>
    </div>
  )
}
