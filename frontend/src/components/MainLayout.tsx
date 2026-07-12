import { useCallback, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { ArticleSearchParams } from '@/api/types'
import { ControlPanel } from '@/components/ControlPanel'
import { IndexingBanner } from '@/components/IndexingBanner'
import { ArticleList } from '@/components/ArticleList'
import { ArticleDetail } from '@/components/ArticleDetail'
import { EMPTY_SEARCH_FILTERS, type SearchFilterValues } from '@/components/SearchFilters'

export function MainLayout() {
  const [filterValues, setFilterValues] = useState<SearchFilterValues>(EMPTY_SEARCH_FILTERS)
  const [selectedId, setSelectedId] = useState<number | null>(null)

  const filters: ArticleSearchParams = {
    q: filterValues.q || undefined,
    sender: filterValues.sender || undefined,
    date_from: filterValues.dateFrom || undefined,
    date_to: filterValues.dateTo || undefined,
  }

  const indexStatusQuery = useQuery({
    queryKey: ['index-status'],
    queryFn: () => api.getIndexStatus(),
    refetchInterval: (query) => ((query.state.data?.pending ?? 0) > 0 ? 5000 : false),
  })
  const pending = indexStatusQuery.data?.pending ?? 0

  const handleFilterChange = useCallback((next: SearchFilterValues) => {
    setFilterValues(next)
  }, [])

  return (
    <div className="flex h-svh flex-col">
      <ControlPanel filters={filterValues} onFilterChange={handleFilterChange} />
      <IndexingBanner pending={pending} />
      <div className="grid min-h-0 flex-1 grid-cols-[360px_1fr]">
        <div className="min-h-0 border-r">
          <ArticleList
            filters={filters}
            selectedId={selectedId}
            onSelect={setSelectedId}
            pollWhileIndexing={pending > 0}
          />
        </div>
        <div className="min-h-0">
          <ArticleDetail articleId={selectedId} />
        </div>
      </div>
    </div>
  )
}
