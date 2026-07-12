import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { ArticleHeader } from '@/components/ArticleHeader'
import { ArticleBody } from '@/components/ArticleBody'
import { getErrorMessage } from '@/lib/errors'

interface ArticleDetailProps {
  articleId: number | null
}

export function ArticleDetail({ articleId }: ArticleDetailProps) {
  const query = useQuery({
    queryKey: ['article', articleId],
    queryFn: () => api.getArticle(articleId as number),
    enabled: articleId !== null,
  })

  if (articleId === null) {
    return (
      <div className="flex h-full items-center justify-center p-6 text-center text-sm text-muted-foreground">
        左の一覧から記事を選択してください。
      </div>
    )
  }

  if (query.isLoading) {
    return (
      <div className="flex flex-col gap-3 p-6">
        <Skeleton className="h-6 w-2/3" />
        <Skeleton className="h-4 w-1/3" />
        <Skeleton className="mt-4 h-4 w-full" />
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-4 w-3/4" />
      </div>
    )
  }

  if (query.isError) {
    return (
      <div className="p-6">
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

  return (
    <ScrollArea className="h-full">
      <div className="flex flex-col gap-4 p-6">
        <ArticleHeader article={query.data} />
        <ArticleBody text={query.data.body_text} />
      </div>
    </ScrollArea>
  )
}
