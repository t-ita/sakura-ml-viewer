import { useEffect, useState } from 'react'

export interface HashRoute {
  path: string
  params: URLSearchParams
}

function parseHash(): HashRoute {
  const hash = window.location.hash.replace(/^#/, '')
  const [path, query] = hash.split('?')
  return {
    path: path || '/',
    params: new URLSearchParams(query ?? ''),
  }
}

/**
 * ハッシュルーティング用フック。ルートが3つだけ(メイン画面/パスワード設定画面)のため
 * ルーターライブラリは導入せず、hashchangeイベントを監視するだけの最小実装にしている。
 */
export function useHashRoute(): HashRoute {
  const [route, setRoute] = useState<HashRoute>(() => parseHash())

  useEffect(() => {
    const onHashChange = () => setRoute(parseHash())
    window.addEventListener('hashchange', onHashChange)
    return () => window.removeEventListener('hashchange', onHashChange)
  }, [])

  return route
}
