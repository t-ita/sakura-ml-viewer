import { useInfiniteQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { ArticleSearchParams } from '@/api/types'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Skeleton } from '@/components/ui/skeleton'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { ArticleListItem } from '@/components/ArticleListItem'
import { getErrorMessage } from '@/lib/errors'

const PER_PAGE = 50

interface ArticleListProps {
  filters: ArticleSearchParams
  selectedId: number | null
  onSelect: (id: number) => void
  pollWhileIndexing: boolean
}

export function ArticleList({ filters, selectedId, onSelect, pollWhileIndexing }: ArticleListProps) {
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
      <div className="flex flex-col gap-2 p-3">
        {Array.from({ length: 10 }).map((_, i) => (
          <Skeleton key={i} className="h-16 w-full" />
        ))}
      </div>
    )
  }

  if (query.isError) {
    return (
      <div className="p-3">
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
      <div className="p-6 text-center text-sm text-muted-foreground">
        条件に一致する記事がありません。
      </div>
    )
  }

  return (
    <ScrollArea className="h-full">
      <div className="flex flex-col">
        {items.map((article) => (
          <ArticleListItem
            key={article.id}
            article={article}
            selected={article.id === selectedId}
            onSelect={() => onSelect(article.id)}
          />
        ))}
      </div>
      <div className="flex flex-col items-center gap-2 p-3">
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
    </ScrollArea>
  )
}
