import { useCallback, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { ArticleSearchParams } from '@/api/types'
import { ControlPanel } from '@/components/ControlPanel'
import { IndexingBanner } from '@/components/IndexingBanner'
import { ArticleCardList } from '@/components/ArticleCardList'
import { EMPTY_SEARCH_FILTERS, type SearchFilterValues } from '@/components/SearchDialog'

export function MainLayout() {
  const [filterValues, setFilterValues] = useState<SearchFilterValues>(EMPTY_SEARCH_FILTERS)
  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set())

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
      <ControlPanel filters={filterValues} onFilterChange={handleFilterChange} />
      <IndexingBanner pending={pending} />
      <ArticleCardList
        filters={filters}
        expandedIds={expandedIds}
        onToggle={handleToggle}
        pollWhileIndexing={pending > 0}
      />
    </div>
  )
}
