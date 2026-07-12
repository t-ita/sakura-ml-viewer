import path from 'node:path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  // サブディレクトリ(例: /ml-viewer/)への配置を前提に、生成アセットの参照は常に相対パスにする。
  base: './',
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  build: {
    outDir: 'dist',
  },
  server: {
    proxy: {
      // 開発時のみ: PHP組み込みサーバーは.htaccessのrewriteを解釈しないため、
      // /api/xxx?... を index.php?route=/xxx&... に変換して転送する。
      // 本番ではApacheのrewriteがこの変換を行うため、この設定はビルド成果物に影響しない。
      '/api': {
        target: 'http://localhost:8099',
        changeOrigin: true,
        // changeOriginはHostヘッダーのみ書き換え、ブラウザが送るOriginヘッダーは
        // フロントエンドの実オリジン(localhost:5173)のまま転送されてしまう。
        // 本番はフロントとAPIが同一オリジンのため問題にならないが、開発時のみ
        // バックエンドのCSRF Origin検証に影響するのでOriginヘッダーを削除して転送する。
        configure: (proxy) => {
          proxy.on('proxyReq', (proxyReq) => {
            proxyReq.removeHeader('origin')
          })
        },
        rewrite: (requestPath) => {
          const url = new URL(requestPath, 'http://localhost')
          const route = url.pathname.replace(/^\/api/, '') || '/'
          const params = new URLSearchParams(url.search)
          params.set('route', route)
          return `/api/index.php?${params.toString()}`
        },
      },
    },
  },
})
