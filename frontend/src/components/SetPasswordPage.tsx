import { useState, type SubmitEvent } from 'react'
import { useHashRoute } from '@/hooks/useHashRoute'
import { api } from '@/api/client'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { getErrorMessage } from '@/lib/errors'

const MIN_PASSWORD_LENGTH = 8
const MAX_PASSWORD_LENGTH = 128

export function SetPasswordPage() {
  const { params } = useHashRoute()
  const token = params.get('token') ?? ''

  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const passwordTooShort = password.length > 0 && password.length < MIN_PASSWORD_LENGTH
  const passwordMismatch = confirmPassword.length > 0 && password !== confirmPassword

  async function handleSubmit(e: SubmitEvent<HTMLFormElement>) {
    e.preventDefault()
    setError(null)

    if (!token) {
      setError('リンクが正しくありません。メールに記載のリンクから再度お試しください。')
      return
    }
    if (password.length < MIN_PASSWORD_LENGTH || password.length > MAX_PASSWORD_LENGTH) {
      setError(`パスワードは${MIN_PASSWORD_LENGTH}文字以上${MAX_PASSWORD_LENGTH}文字以内で入力してください。`)
      return
    }
    if (password !== confirmPassword) {
      setError('パスワードが一致しません。')
      return
    }

    setSubmitting(true)
    try {
      await api.setPassword(token, password)
      setDone(true)
    } catch (err) {
      setError(getErrorMessage(err))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-svh items-center justify-center p-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle>パスワードの設定</CardTitle>
          <CardDescription>新しいパスワードを設定してください。</CardDescription>
        </CardHeader>
        <CardContent>
          {done ? (
            <div className="flex flex-col gap-4">
              <Alert>
                <AlertDescription>
                  パスワードを設定しました。ログイン画面からログインしてください。
                </AlertDescription>
              </Alert>
              <Button
                type="button"
                onClick={() => {
                  window.location.hash = '#/'
                }}
              >
                ログイン画面へ
              </Button>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="flex flex-col gap-4">
              {!token && (
                <Alert variant="destructive">
                  <AlertDescription>
                    リンクが正しくありません。メールに記載のリンクから再度お試しください。
                  </AlertDescription>
                </Alert>
              )}
              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="new-password">新しいパスワード</Label>
                <Input
                  id="new-password"
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={MIN_PASSWORD_LENGTH}
                  maxLength={MAX_PASSWORD_LENGTH}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={submitting}
                  aria-invalid={passwordTooShort}
                />
                {passwordTooShort && (
                  <p className="text-xs text-destructive">
                    {MIN_PASSWORD_LENGTH}文字以上で入力してください。
                  </p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="confirm-password">新しいパスワード（確認）</Label>
                <Input
                  id="confirm-password"
                  type="password"
                  autoComplete="new-password"
                  required
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  disabled={submitting}
                  aria-invalid={passwordMismatch}
                />
                {passwordMismatch && (
                  <p className="text-xs text-destructive">パスワードが一致しません。</p>
                )}
              </div>
              <Button type="submit" disabled={submitting || !token}>
                {submitting ? '設定中...' : 'パスワードを設定する'}
              </Button>
            </form>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
