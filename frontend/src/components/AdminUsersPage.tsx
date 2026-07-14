import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { AdminUserItem } from '@/api/types'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { getErrorMessage } from '@/lib/errors'

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

function UserRow({ item }: { item: AdminUserItem }) {
  return (
    <TableRow>
      <TableCell className="font-medium">{item.email}</TableCell>
      <TableCell>
        {item.password_registered ? (
          <Badge variant="secondary">登録済み</Badge>
        ) : (
          <Badge variant="outline">未登録</Badge>
        )}
      </TableCell>
      <TableCell>{formatDateTime(item.created_at)}</TableCell>
      <TableCell>{formatDateTime(item.last_login_at)}</TableCell>
      <TableCell>{item.pending_token ? <Badge variant="secondary">あり</Badge> : '—'}</TableCell>
    </TableRow>
  )
}

export function AdminUsersPage() {
  const query = useQuery({ queryKey: ['admin-users'], queryFn: () => api.getAdminUsers() })

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 p-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">ユーザー管理</h1>
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

      {query.data && (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg border p-3">
              <p className="text-xs text-muted-foreground">会員数</p>
              <p className="text-2xl font-semibold">{query.data.summary.active_members}</p>
            </div>
            <div className="rounded-lg border p-3">
              <p className="text-xs text-muted-foreground">パスワード登録済み</p>
              <p className="text-2xl font-semibold">{query.data.summary.password_registered}</p>
            </div>
            <div className="rounded-lg border p-3">
              <p className="text-xs text-muted-foreground">残骸ユーザー</p>
              <p className="text-2xl font-semibold">{query.data.summary.orphan_users}</p>
            </div>
          </div>

          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>メールアドレス</TableHead>
                <TableHead>登録状況</TableHead>
                <TableHead>登録日時</TableHead>
                <TableHead>最終ログイン</TableHead>
                <TableHead>発行中トークン</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {query.data.members.map((item) => (
                <UserRow key={item.email} item={item} />
              ))}
            </TableBody>
          </Table>

          {query.data.orphan_users.length > 0 && (
            <div className="flex flex-col gap-2">
              <h2 className="text-sm font-semibold text-destructive">
                activesに存在しないユーザー
              </h2>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>メールアドレス</TableHead>
                    <TableHead>登録状況</TableHead>
                    <TableHead>登録日時</TableHead>
                    <TableHead>最終ログイン</TableHead>
                    <TableHead>発行中トークン</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.data.orphan_users.map((item) => (
                    <UserRow key={item.email} item={item} />
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </>
      )}
    </div>
  )
}
