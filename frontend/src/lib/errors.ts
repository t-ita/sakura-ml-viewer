import { ApiError } from '@/api/client'

export function getErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    return err.message
  }
  return 'エラーが発生しました。しばらくしてから再度お試しください。'
}
