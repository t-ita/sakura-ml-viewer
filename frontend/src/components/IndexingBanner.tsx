import { Loader2Icon } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'

interface IndexingBannerProps {
  pending: number
}

export function IndexingBanner({ pending }: IndexingBannerProps) {
  if (pending <= 0) {
    return null
  }

  return (
    <Alert className="gap-2 rounded-none border-x-0 border-t-0">
      <Loader2Icon className="size-4 animate-spin" />
      <AlertDescription>
        記事インデックスを構築中です（残り約{pending}件）。しばらくすると自動的に反映されます。
      </AlertDescription>
    </Alert>
  )
}
