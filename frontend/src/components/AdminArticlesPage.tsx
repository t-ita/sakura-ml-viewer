import { useState } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { AdminArticleItem, AdminArticleStatus } from '@/api/types'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { getErrorMessage } from '@/lib/errors'

const PER_PAGE = 50

const STATUS_OPTIONS: { value: AdminArticleStatus; label: string }[] = [
  { value: 'all', label: 'すべて' },
  { value: 'ok', label: 'ok' },
  { value: 'partial', label: 'partial' },
  { value: 'error', label: 'error' },
]

function formatDateTime(iso: string | null): string {
  if (!iso) {
    return '—'
  }
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) {
    return '—'
  }
  return d.toLocaleString('ja-JP', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function statusBadge(status: AdminArticleItem['parse_status']) {
  if (status === 'error') {
    return <Badge variant="destructive">error</Badge>
  }
  if (status === 'partial') {
    return <Badge variant="outline">partial</Badge>
  }
  return <Badge variant="secondary">ok</Badge>
}

export function AdminArticlesPage() {
  const [status, setStatus] = useState<AdminArticleStatus>('all')

  const query = useInfiniteQuery({
    queryKey: ['admin-articles', status],
    queryFn: ({ pageParam }) => api.listAdminArticles({ status, page: pageParam, per_page: PER_PAGE }),
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      const loaded = lastPage.page * lastPage.per_page
      return loaded < lastPage.total ? lastPage.page + 1 : undefined
    },
  })

  const items = query.data?.pages.flatMap((p) => p.items) ?? []
  const total = query.data?.pages[0]?.total ?? 0
  const summary = query.data?.pages[0]?.summary

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 p-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">記事管理</h1>
        <Button variant="ghost" size="sm" asChild>
          <a href="#/">← 記事一覧へ戻る</a>
        </Button>
      </div>

      {query.isLoading && (
        <div className="flex flex-col gap-3">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-40 w-full" />
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

      {summary && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          <div className="rounded-lg border p-3">
            <p className="text-xs text-muted-foreground">seq</p>
            <p className="text-2xl font-semibold">{summary.seq}</p>
          </div>
          <div className="rounded-lg border p-3">
            <p className="text-xs text-muted-foreground">indexed_max</p>
            <p className="text-2xl font-semibold">{summary.indexed_max}</p>
          </div>
          <div className={`rounded-lg border p-3 ${summary.pending > 0 ? 'border-destructive' : ''}`}>
            <p className="text-xs text-muted-foreground">未処理</p>
            <p className={`text-2xl font-semibold ${summary.pending > 0 ? 'text-destructive' : ''}`}>
              {summary.pending}
            </p>
          </div>
          <div className="rounded-lg border p-3">
            <p className="text-xs text-muted-foreground">ok / partial</p>
            <p className="text-2xl font-semibold">
              {summary.count_ok} / {summary.count_partial}
            </p>
          </div>
          <div className={`rounded-lg border p-3 ${summary.count_error > 0 ? 'border-destructive' : ''}`}>
            <p className="text-xs text-muted-foreground">error</p>
            <p className={`text-2xl font-semibold ${summary.count_error > 0 ? 'text-destructive' : ''}`}>
              {summary.count_error}
            </p>
          </div>
        </div>
      )}

      <div className="flex items-center gap-2">
        <span className="text-sm text-muted-foreground">状態:</span>
        <Select value={status} onValueChange={(v) => setStatus(v as AdminArticleStatus)}>
          <SelectTrigger size="sm">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {STATUS_OPTIONS.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {query.data && (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>記事番号</TableHead>
                <TableHead>件名</TableHead>
                <TableHead>送信者</TableHead>
                <TableHead>日付</TableHead>
                <TableHead>状態</TableHead>
                <TableHead>インデックス登録日時</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>{item.id}</TableCell>
                  <TableCell className="max-w-64 truncate">{item.subject || '(件名なし)'}</TableCell>
                  <TableCell className="max-w-40 truncate">{item.from_addr}</TableCell>
                  <TableCell>{formatDateTime(item.date)}</TableCell>
                  <TableCell>{statusBadge(item.parse_status)}</TableCell>
                  <TableCell>{formatDateTime(item.indexed_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

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
        </>
      )}
    </div>
  )
}
