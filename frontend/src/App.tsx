import { useSession } from '@/context/SessionContext'
import { useHashRoute } from '@/hooks/useHashRoute'
import { LoginPage } from '@/components/LoginPage'
import { SetPasswordPage } from '@/components/SetPasswordPage'
import { MainLayout } from '@/components/MainLayout'

function App() {
  const session = useSession()
  const { path } = useHashRoute()

  if (path === '/set-password') {
    return <SetPasswordPage />
  }

  if (session.status === 'loading') {
    return (
      <div className="flex min-h-svh items-center justify-center text-sm text-muted-foreground">
        読み込み中...
      </div>
    )
  }

  if (session.status === 'anonymous') {
    return <LoginPage />
  }

  return <MainLayout />
}

export default App
