import { useEffect, useRef, useState } from 'react'
import type { DateRange } from 'react-day-picker'
import { format } from 'date-fns'
import { ja } from 'date-fns/locale'
import { CalendarIcon, SearchIcon, XIcon } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Calendar } from '@/components/ui/calendar'

export interface SearchFilterValues {
  q: string
  sender: string
  dateFrom: string
  dateTo: string
}

export const EMPTY_SEARCH_FILTERS: SearchFilterValues = {
  q: '',
  sender: '',
  dateFrom: '',
  dateTo: '',
}

interface SearchFiltersProps {
  value: SearchFilterValues
  onChange: (value: SearchFilterValues) => void
}

const DEBOUNCE_MS = 300

export function SearchFilters({ value, onChange }: SearchFiltersProps) {
  const [qInput, setQInput] = useState(value.q)
  const [senderInput, setSenderInput] = useState(value.sender)

  // onChangeが呼ばれるたびに変わる可能性のあるvalue(日付範囲を含む)を
  // デバウンス発火時点の最新値で参照するためのref。
  const valueRef = useRef(value)
  valueRef.current = value

  useEffect(() => {
    const timer = setTimeout(() => {
      onChange({ ...valueRef.current, q: qInput, sender: senderInput })
    }, DEBOUNCE_MS)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qInput, senderInput])

  const dateRange: DateRange | undefined =
    value.dateFrom || value.dateTo
      ? {
          from: value.dateFrom ? new Date(`${value.dateFrom}T00:00:00`) : undefined,
          to: value.dateTo ? new Date(`${value.dateTo}T00:00:00`) : undefined,
        }
      : undefined

  function handleDateSelect(range: DateRange | undefined) {
    onChange({
      ...valueRef.current,
      dateFrom: range?.from ? format(range.from, 'yyyy-MM-dd') : '',
      dateTo: range?.to ? format(range.to, 'yyyy-MM-dd') : '',
    })
  }

  const hasDateFilter = Boolean(value.dateFrom || value.dateTo)
  const hasAnyFilter = Boolean(qInput || senderInput || hasDateFilter)

  function clearAll() {
    setQInput('')
    setSenderInput('')
    onChange(EMPTY_SEARCH_FILTERS)
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="relative w-44 sm:w-56">
        <SearchIcon className="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          placeholder="キーワード検索"
          className="pl-7"
          value={qInput}
          onChange={(e) => setQInput(e.target.value)}
        />
      </div>
      <Input
        placeholder="送信者"
        className="w-32 sm:w-40"
        value={senderInput}
        onChange={(e) => setSenderInput(e.target.value)}
      />
      <Popover>
        <PopoverTrigger asChild>
          <Button variant="outline" size="sm">
            <CalendarIcon />
            {hasDateFilter ? `${value.dateFrom || '…'} 〜 ${value.dateTo || '…'}` : '日付範囲'}
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0" align="start">
          <Calendar mode="range" selected={dateRange} onSelect={handleDateSelect} locale={ja} />
        </PopoverContent>
      </Popover>
      {hasAnyFilter && (
        <Button variant="ghost" size="sm" onClick={clearAll}>
          <XIcon />
          条件をクリア
        </Button>
      )}
    </div>
  )
}
