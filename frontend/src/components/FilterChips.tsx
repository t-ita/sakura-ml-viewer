import { XIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import type { SearchFilterValues } from '@/components/SearchDialog'

interface FilterChipsProps {
  value: SearchFilterValues
  onClear: () => void
}

export function FilterChips({ value, onClear }: FilterChipsProps) {
  const chips: string[] = []

  if (value.idFrom || value.idTo) {
    chips.push(`記事番号 ${value.idFrom || '…'} 〜 ${value.idTo || '…'}`)
  }
  if (value.sender) {
    chips.push(`送信者: ${value.sender}`)
  }
  if (value.q) {
    chips.push(`キーワード: ${value.q}`)
  }
  if (value.dateFrom || value.dateTo) {
    chips.push(`${value.dateFrom || '…'} 〜 ${value.dateTo || '…'}`)
  }

  if (chips.length === 0) {
    return null
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5 border-b px-3 py-2">
      {chips.map((chip) => (
        <Badge key={chip} variant="outline" className="font-normal">
          {chip}
        </Badge>
      ))}
      <Button variant="ghost" size="sm" onClick={onClear}>
        <XIcon />
        絞り込み解除
      </Button>
    </div>
  )
}
