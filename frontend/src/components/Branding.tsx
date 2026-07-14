import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'

// DESIGN.md §6.6: logo.png → title.txtの1行目 → 既定文字列 の順にフォールバックする。
// どちらも運用者がFTPで直接設置する想定で、バックエンド・config.phpの変更は不要。
const DEFAULT_TITLE = 'ML Viewer'

async function fetchBrandingTitle(): Promise<string | null> {
  const res = await fetch('branding/title.txt')
  if (!res.ok) {
    return null
  }
  // 一部の環境(開発サーバーのSPAフォールバック等)は存在しないパスにも200でHTMLを
  // 返すことがあるため、Content-Typeで弾く(text/plain以外は無視してデフォルトへ)。
  const contentType = res.headers.get('content-type') ?? ''
  if (!contentType.includes('text/plain')) {
    return null
  }
  const text = await res.text()
  const firstLine = text.split(/\r?\n/)[0]?.trim()
  return firstLine || null
}

// <img onError>だとブラウザが実際にリクエストを試みてコンソールに404が残るため、
// fetchで事前確認してから<img>を出すかどうかを決める。
async function checkLogoExists(): Promise<boolean> {
  try {
    const res = await fetch('branding/logo.png')
    if (!res.ok) {
      return false
    }
    const contentType = res.headers.get('content-type') ?? ''
    return contentType.startsWith('image/')
  } catch {
    return false
  }
}

export function Branding() {
  const { data: customTitle } = useQuery({
    queryKey: ['branding-title'],
    queryFn: fetchBrandingTitle,
    staleTime: Infinity,
    retry: false,
  })

  const { data: logoExists } = useQuery({
    queryKey: ['branding-logo-exists'],
    queryFn: checkLogoExists,
    staleTime: Infinity,
    retry: false,
  })

  const resolvedTitle = customTitle ?? DEFAULT_TITLE

  useEffect(() => {
    document.title = resolvedTitle
  }, [resolvedTitle])

  if (logoExists) {
    return (
      <img
        src="branding/logo.png"
        alt={resolvedTitle}
        className="h-8 max-h-8 w-auto shrink-0 object-contain"
      />
    )
  }

  return <span className="truncate text-base font-semibold">{resolvedTitle}</span>
}
