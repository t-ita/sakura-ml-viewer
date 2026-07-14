import { FileTextIcon, ShieldIcon, UsersIcon } from 'lucide-react'
import { useSession } from '@/context/SessionContext'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

/**
 * is_adminのときのみ表示する管理メニュー(DESIGN.md §6.9)。
 * 表示制御はUI上の便宜にすぎず、実際の権限境界は各管理APIのサーバー側判定(§5.8)。
 */
export function AdminMenu() {
  const { isAdmin } = useSession()

  if (!isAdmin) {
    return null
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm">
          <ShieldIcon />
          管理機能
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel>管理機能</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => { window.location.hash = '#/admin/users' }}>
          <UsersIcon />
          ユーザー管理
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => { window.location.hash = '#/admin/articles' }}>
          <FileTextIcon />
          記事管理
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
