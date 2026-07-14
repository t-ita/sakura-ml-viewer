import { useState, type SubmitEvent } from 'react'
import type { DateRange } from 'react-day-picker'
import { format } from 'date-fns'
import { ja } from 'date-fns/locale'
import { FilterIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Calendar } from '@/components/ui/calendar'

export interface SearchFilterValues {
  idFrom: string
  idTo: string
  sender: string
  q: string
  dateFrom: string
  dateTo: string
}

export const EMPTY_SEARCH_FILTERS: SearchFilterValues = {
  idFrom: '',
  idTo: '',
  sender: '',
  q: '',
  dateFrom: '',
  dateTo: '',
}

interface SearchDialogProps {
  value: SearchFilterValues
  onApply: (value: SearchFilterValues) => void
}

// バックエンドのmlv_query_int_param()と同じ範囲(1〜999999999、先頭ゼロ不可)にする
const ID_PATTERN = /^[1-9][0-9]{0,8}$/

function validateIdField(v: string): string | null {
  if (v === '') {
    return null
  }
  return ID_PATTERN.test(v) ? null : '1〜999999999の整数で入力してください。'
}

export function SearchDialog({ value, onApply }: SearchDialogProps) {
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState<SearchFilterValues>(value)

  function handleOpenChange(next: boolean) {
    if (next) {
      // 開くたびに現在適用中の条件を復元する(DESIGN.md §6.3)
      setDraft(value)
    }
    setOpen(next)
  }

  const idFromError = validateIdField(draft.idFrom)
  const idToError = validateIdField(draft.idTo)
  const canApply = idFromError === null && idToError === null

  const dateRange: DateRange | undefined =
    draft.dateFrom || draft.dateTo
      ? {
          from: draft.dateFrom ? new Date(`${draft.dateFrom}T00:00:00`) : undefined,
          to: draft.dateTo ? new Date(`${draft.dateTo}T00:00:00`) : undefined,
        }
      : undefined

  function handleDateSelect(range: DateRange | undefined) {
    setDraft((d) => ({
      ...d,
      dateFrom: range?.from ? format(range.from, 'yyyy-MM-dd') : '',
      dateTo: range?.to ? format(range.to, 'yyyy-MM-dd') : '',
    }))
  }

  function handleApply(e: SubmitEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!canApply) {
      return
    }
    onApply(draft)
    setOpen(false)
  }

  function handleClear() {
    setDraft(EMPTY_SEARCH_FILTERS)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <FilterIcon />
          投稿絞り込み
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>投稿の絞り込み</DialogTitle>
          <DialogDescription>条件を指定して「絞り込み」を押してください。</DialogDescription>
        </DialogHeader>
        <form onSubmit={handleApply} className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label>記事番号の範囲</Label>
            <div className="flex items-center gap-2">
              <Input
                inputMode="numeric"
                placeholder="から"
                value={draft.idFrom}
                onChange={(e) => setDraft((d) => ({ ...d, idFrom: e.target.value.trim() }))}
                aria-invalid={idFromError !== null}
              />
              <span className="text-muted-foreground">〜</span>
              <Input
                inputMode="numeric"
                placeholder="まで"
                value={draft.idTo}
                onChange={(e) => setDraft((d) => ({ ...d, idTo: e.target.value.trim() }))}
                aria-invalid={idToError !== null}
              />
            </div>
            {(idFromError ?? idToError) && (
              <p className="text-xs text-destructive">{idFromError ?? idToError}</p>
            )}
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="search-sender">投稿者名</Label>
            <Input
              id="search-sender"
              placeholder="アドレスまたは表示名"
              value={draft.sender}
              onChange={(e) => setDraft((d) => ({ ...d, sender: e.target.value }))}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="search-q">キーワード（件名・本文）</Label>
            <Input
              id="search-q"
              placeholder="スペース区切りでAND検索"
              value={draft.q}
              onChange={(e) => setDraft((d) => ({ ...d, q: e.target.value }))}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>日付範囲</Label>
            <div className="flex justify-center rounded-lg border">
              <Calendar mode="range" selected={dateRange} onSelect={handleDateSelect} locale={ja} />
            </div>
          </div>

          <DialogFooter className="sm:justify-between">
            <Button type="button" variant="ghost" onClick={handleClear}>
              クリア
            </Button>
            <Button type="submit" disabled={!canApply}>
              絞り込み
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
