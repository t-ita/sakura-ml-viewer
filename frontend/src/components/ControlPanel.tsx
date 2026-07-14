import { Branding } from '@/components/Branding'
import { EMPTY_SEARCH_FILTERS, SearchDialog, type SearchFilterValues } from '@/components/SearchDialog'
import { FilterChips } from '@/components/FilterChips'
import { UserMenu } from '@/components/UserMenu'

interface ControlPanelProps {
  filters: SearchFilterValues
  onFilterChange: (value: SearchFilterValues) => void
}

export function ControlPanel({ filters, onFilterChange }: ControlPanelProps) {
  return (
    <div className="sticky top-0 z-10 bg-background">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b p-3">
        <Branding />
        <div className="flex items-center gap-2">
          <SearchDialog value={filters} onApply={onFilterChange} />
          <UserMenu />
        </div>
      </div>
      <FilterChips value={filters} onClear={() => onFilterChange(EMPTY_SEARCH_FILTERS)} />
    </div>
  )
}
