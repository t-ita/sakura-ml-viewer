import { useCallback, useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { ArticleSearchParams } from '@/api/types'
import { useSession } from '@/context/SessionContext'
import { useHashRoute } from '@/hooks/useHashRoute'
import { AdminArticlesPage } from '@/components/AdminArticlesPage'
import { AdminUsersPage } from '@/components/AdminUsersPage'
import { ControlPanel } from '@/components/ControlPanel'
import { IndexingBanner } from '@/components/IndexingBanner'
import { ArticleCardList } from '@/components/ArticleCardList'
import { EMPTY_SEARCH_FILTERS, type SearchFilterValues } from '@/components/SearchDialog'

const ADMIN_ROUTES = ['/admin/users', '/admin/articles']

export function MainLayout() {
  const { isAdmin } = useSession()
  const { path } = useHashRoute()
  const isAdminRoute = ADMIN_ROUTES.includes(path)

  const [filterValues, setFilterValues] = useState<SearchFilterValues>(EMPTY_SEARCH_FILTERS)
  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set())

  useEffect(() => {
    // 非管理者が#/admin/*を直打ちした場合、一覧画面へ戻す。
    // 本当の権限境界は各管理APIのサーバー側判定であり、これはUI上の便宜。
    if (isAdminRoute && !isAdmin) {
      window.location.hash = '#/'
    }
  }, [isAdminRoute, isAdmin])

  const filters: ArticleSearchParams = {
    q: filterValues.q || undefined,
    sender: filterValues.sender || undefined,
    date_from: filterValues.dateFrom || undefined,
    date_to: filterValues.dateTo || undefined,
    id_from: filterValues.idFrom ? Number(filterValues.idFrom) : undefined,
    id_to: filterValues.idTo ? Number(filterValues.idTo) : undefined,
  }

  const indexStatusQuery = useQuery({
    queryKey: ['index-status'],
    queryFn: () => api.getIndexStatus(),
    // 管理画面(記事管理)は「観察してもインデックスを進行させない」設計(§4.5)のため、
    // このポーリング自体を止める(GET /index/statusもmlv_maybe_index()を実行するため)。
    enabled: !isAdminRoute,
    refetchInterval: (query) => ((query.state.data?.pending ?? 0) > 0 ? 5000 : false),
  })
  const pending = indexStatusQuery.data?.pending ?? 0

  const handleFilterChange = useCallback((next: SearchFilterValues) => {
    setFilterValues(next)
    // 絞り込み条件が変わると一覧の内容が変わるため、展開中カードの状態は引き継がない
    setExpandedIds(new Set())
  }, [])

  const handleToggle = useCallback((id: number) => {
    setExpandedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }, [])

  return (
    <div className="min-h-svh">
      <ControlPanel
        filters={filterValues}
        onFilterChange={handleFilterChange}
        isAdminRoute={isAdminRoute}
      />
      {path === '/admin/users' && isAdmin && <AdminUsersPage />}
      {path === '/admin/articles' && isAdmin && <AdminArticlesPage />}
      {!isAdminRoute && (
        <>
          <IndexingBanner pending={pending} />
          <ArticleCardList
            filters={filters}
            expandedIds={expandedIds}
            onToggle={handleToggle}
            pollWhileIndexing={pending > 0}
          />
        </>
      )}
    </div>
  )
}
