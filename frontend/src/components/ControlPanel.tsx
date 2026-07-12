import { SearchFilters, type SearchFilterValues } from '@/components/SearchFilters'
import { UserMenu } from '@/components/UserMenu'

interface ControlPanelProps {
  filters: SearchFilterValues
  onFilterChange: (value: SearchFilterValues) => void
}

export function ControlPanel({ filters, onFilterChange }: ControlPanelProps) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-b p-3">
      <SearchFilters value={filters} onChange={onFilterChange} />
      <UserMenu />
    </div>
  )
}
