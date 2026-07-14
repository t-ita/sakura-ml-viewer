import { AdminMenu } from '@/components/AdminMenu'
import { Branding } from '@/components/Branding'
import { EMPTY_SEARCH_FILTERS, SearchDialog, type SearchFilterValues } from '@/components/SearchDialog'
import { FilterChips } from '@/components/FilterChips'
import { UserMenu } from '@/components/UserMenu'

interface ControlPanelProps {
  filters: SearchFilterValues
  onFilterChange: (value: SearchFilterValues) => void
  isAdminRoute: boolean
}

export function ControlPanel({ filters, onFilterChange, isAdminRoute }: ControlPanelProps) {
  return (
    <div className="sticky top-0 z-10 bg-background">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b p-3">
        <Branding />
        <div className="flex items-center gap-2">
          {!isAdminRoute && <SearchDialog value={filters} onApply={onFilterChange} />}
          <AdminMenu />
          <UserMenu />
        </div>
      </div>
      {!isAdminRoute && (
        <FilterChips value={filters} onClear={() => onFilterChange(EMPTY_SEARCH_FILTERS)} />
      )}
    </div>
  )
}
