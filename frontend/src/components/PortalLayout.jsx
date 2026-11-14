import { useEffect, useMemo, useRef, useState } from 'react'
import { post } from '../lib/api'
import { navigation, pageMetadata } from '../lib/navigation'
import { guardLeave } from '../lib/navigationGuard'
import { nameInitials } from '../lib/initials'
import { pendingApprovalsLabel } from '../lib/pendingApprovals'
import { useResource } from '../hooks/useResource'
import { Icon } from './ui/Icon'
import { BrandMark } from './BrandMark'
import { activeTheme, chooseTheme, oppositeTheme } from '../lib/theme'

// [R3] Item do menu que leva o contador de pendências (só o número).
const PENDING_PATH = '/admin'
const PENDING_INTERVAL_MS = 60000
// [R3, D-R0-7] Barra de atalhos do celular: as telas de todo dia e o menu.
// Cada atalho passa pelo navigate (a guarda do formulário sujo, 17f).
const TABBAR = [
  { path: '/dashboard', label: 'Agora', icon: 'dashboard' },
  { path: '/pausas', label: 'Pausas', icon: 'pause', permission: 'pausas.use' },
  { path: '/escala-presencial', label: 'Escala', icon: 'calendar' },
]

function userLabel(session) {
  return session.ci.autenticado
    ? session.ci.nome
    : session.gestor.username || 'Usuário'
}

// [R1] Troca o tema na sessão (D-R1-1). O rótulo diz a ação, não o estado.
export function ThemeToggle() {
  const [theme, setTheme] = useState(() => (typeof document === 'undefined' ? 'light' : activeTheme()))
  const next = oppositeTheme(theme)
  const label = next === 'dark' ? 'Usar tema escuro' : 'Usar tema claro'
  return (
    <button
      aria-label={label}
      className="rounded-lg border border-line bg-surface p-2.5 text-ink-2 hover:border-line-strong hover:bg-surface-2"
      data-theme-toggle={next}
      onClick={() => { chooseTheme(next); setTheme(next) }}
      title={label}
      type="button"
    >
      <Icon name={next === 'dark' ? 'moon' : 'sun'} />
    </button>
  )
}

// [R3] Hora do servidor (o status.php acerta a diferença do relógio local a
// cada leitura), no lugar da hora do navegador. Sem leitura ainda, a do
// navegador. Só hora e minuto: o segundo correndo no topo distrai.
export function Clock({ serverNow }) {
  const instant = Number.isFinite(serverNow) ? new Date(serverNow) : new Date()
  const text = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(instant)
  return (
    <p className="hidden items-baseline gap-2 whitespace-nowrap text-sm text-muted sm:flex" data-server-clock="">
      Servidor <time className="font-mono font-medium tabular-nums text-ink-2">{text}</time>
    </p>
  )
}

// [R3] Primeiro Tab da casca. Não muda o endereço (o roteador trataria o
// "#conteudo" como navegação): só leva o foco ao conteúdo.
function skipToContent(event) {
  event.preventDefault()
  document.getElementById('conteudo')?.focus()
}

export function PortalLayout({ session, path, navigate, onLogout, serverNow, children }) {
  const [mobileOpen, setMobileOpen] = useState(false)
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const notificationsPanelId = 'portal-notifications-panel'
  const notificationResource = useResource('portal/notifications.php')
  const refreshNotifications = notificationResource.refresh
  const permissions = useMemo(() => new Set(session.permissions || []), [session.permissions])
  // [R3] Contador de pendências: só para quem decide (o endpoint exige a
  // gestão), a cada 60 s com a aba visível e a cada troca de tela.
  const canApprove = permissions.has('operacao.approve')
  const pendingResource = useResource('solicitacoes_pendentes.php', { enabled: canApprove, intervalMs: PENDING_INTERVAL_MS })
  const refreshPending = pendingResource.refresh
  const pendingLabel = canApprove ? pendingApprovalsLabel(pendingResource.data) : null
  const firstPathRef = useRef(true)
  const currentUserLabel = userLabel(session)
  const userInitials = nameInitials(currentUserLabel, 'S')
  const metadata = pageMetadata[path] || ['Portal Operacional', 'Módulo do Portal SDK.']

  useEffect(() => {
    const timer = window.setInterval(() => refreshNotifications(), 60000)
    return () => window.clearInterval(timer)
  }, [refreshNotifications])

  useEffect(() => {
    if (firstPathRef.current) {
      firstPathRef.current = false
      return
    }
    if (canApprove) refreshPending()
  }, [canApprove, path, refreshPending])

  function goTo(target) {
    navigate(target)
    setMobileOpen(false)
  }

  async function openNotification(notification) {
    try {
      if (!notification.is_read) {
        await post('portal/notifications.php', { id: notification.id })
        refreshNotifications()
      }
    } catch {
      // A navegação relacionada continua disponível mesmo se a leitura não persistir.
    }
    if (notification.related_url?.includes('/app/')) {
      const appRoute = notification.related_url.slice(notification.related_url.indexOf('/app/') + 4)
      goTo(appRoute)
    }
    setNotificationsOpen(false)
  }

  function navContent(compact = false, showCollapseToggle = false, idPrefix = 'nav') {
    const countId = `${idPrefix}-pending-count`
    return (
    <>
      <div className={`flex items-center border-b border-line ${compact ? 'h-24 flex-col justify-center gap-2 px-2' : 'h-16 gap-3 px-5'}`}>
        <BrandMark className="h-8 w-8 text-xs" />
        {!compact && <p className="min-w-0 truncate font-semibold text-ink">Portal SDK</p>}
        {showCollapseToggle && (
          <button
            aria-expanded={!compact}
            aria-label={compact ? 'Expandir menu lateral' : 'Minimizar menu lateral'}
            className={`rounded-lg border border-line bg-surface p-2 text-muted transition hover:border-line-strong hover:bg-surface-2 hover:text-ink ${compact ? '' : 'ml-auto'}`}
            onClick={() => setSidebarCollapsed((collapsed) => !collapsed)}
            title={compact ? 'Expandir menu' : 'Minimizar menu'}
            type="button"
          >
            <Icon name="menu" className="h-4 w-4" />
          </button>
        )}
      </div>
      <nav className="sidebar-scroll flex-1 space-y-0.5 overflow-y-auto px-3 py-4" aria-label="Módulos do portal">
        {navigation.map((item, index) => {
          if (item.section) {
            if (compact) {
              return <div className="my-3 h-px bg-line" key={`${item.section}-${index}`} aria-hidden="true" />
            }
            return <p className="px-3 pb-1.5 pt-4 text-xs font-medium text-muted first:pt-1" key={`${item.section}-${index}`}>{item.section}</p>
          }
          if (item.permission && !permissions.has(item.permission)) return null
          const active = path === item.path
          // O número fica fora do botão: o nome do item continua só o rótulo
          // (o roteiro de navegador acha o item pelo texto) e o leitor de tela
          // ouve a contagem como descrição.
          const count = item.path === PENDING_PATH ? pendingLabel : null
          return (
            <div className="relative" key={item.path}>
              <button
                aria-current={active ? 'page' : undefined}
                aria-describedby={count ? countId : undefined}
                className={`sidebar-link ${compact ? 'justify-center px-2' : ''} ${count && !compact ? 'pr-10' : ''} ${active ? 'sidebar-link-active' : ''}`}
                onClick={() => goTo(item.path)}
                title={compact ? item.label : undefined}
                type="button"
              >
                <span className={`grid h-6 w-6 place-items-center ${active ? 'text-ink' : 'text-muted'}`}>
                  <Icon name={item.icon} className="h-[17px] w-[17px]" />
                </span>
                {!compact && <span className="min-w-0 flex-1 truncate">{item.label}</span>}
              </button>
              {count && (
                <span className={`nav-count ${compact ? 'nav-count-compact' : ''}`} data-nav-count="" id={countId}>
                  <span aria-hidden="true">{count}</span>
                  <span className="sr-only">{count} pendentes</span>
                </span>
              )}
            </div>
          )
        })}
      </nav>
      <div className={`border-t border-line ${compact ? 'p-2' : 'p-4'}`} title={compact ? 'Desenvolvido por Matheus Camargo' : undefined}>
        <div className={`flex items-center gap-3 ${compact ? 'justify-center' : ''}`} title={compact ? currentUserLabel : undefined}>
          <span aria-hidden="true" className="user-avatar h-9 w-9">{userInitials}</span>
          {!compact && (
            <div className="min-w-0">
              <p className="truncate text-sm font-medium text-ink">{currentUserLabel}</p>
              {/* [R4a] O login do técnico, que estava no cartão "Sessão do colaborador" de Pausas. */}
              <p className="truncate text-xs text-muted">
                {session.ci.autenticado && session.ci.username && <span className="font-mono" data-user-login="">{session.ci.username} · </span>}
                <span className="capitalize">{session.role?.replace('_', ' ')}</span>
              </p>
            </div>
          )}
        </div>
        {!compact && <p className="mt-3 text-xs text-muted">Desenvolvido por Matheus Camargo</p>}
      </div>
    </>
    )
  }

  return (
    <div className="portal-tech-shell min-h-screen bg-bg">
      <a className="skip-link" href="#conteudo" onClick={skipToContent}>Ir para o conteúdo</a>
      <aside className={`fixed inset-y-0 left-0 z-40 hidden border-r border-line bg-surface transition-[width] duration-200 lg:flex lg:flex-col ${sidebarCollapsed ? 'w-20' : 'w-64'}`}>
        {navContent(sidebarCollapsed, true, 'nav-lateral')}
      </aside>
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button className="absolute inset-0 bg-black/70" aria-label="Fechar menu" onClick={() => setMobileOpen(false)} type="button" />
          {/* [R3] No celular, o menu ocupa a tela inteira (folha); de 640 px para cima, a coluna de antes. */}
          <aside className="relative flex h-full w-full flex-col bg-surface sm:w-80 sm:border-r sm:border-line" role="dialog" aria-label="Menu principal" aria-modal="true">
            <button className="absolute right-3 top-3 rounded-lg p-2.5 text-muted" onClick={() => setMobileOpen(false)} type="button" aria-label="Fechar menu">
              <Icon name="close" />
            </button>
            {navContent(false, false, 'nav-celular')}
          </aside>
        </div>
      )}

      <div className={`min-w-0 transition-[padding] duration-200 ${sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64'}`}>
        <header className="sticky top-0 z-30 border-b border-line bg-bg">
          <div className="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
            <button className="rounded-lg border border-line bg-surface p-2.5 text-ink-2 lg:hidden" onClick={() => setMobileOpen(true)} type="button" aria-label="Abrir menu">
              <Icon name="menu" />
            </button>
            <h1 className="min-w-0 flex-1 truncate text-lg font-semibold text-ink outline-none sm:text-xl" data-page-title="" tabIndex={-1}>{metadata[0]}</h1>
            <div className="flex items-center gap-2 sm:gap-3">
              <Clock serverNow={serverNow} />
              <ThemeToggle />
              <div className="relative">
                <button aria-controls={notificationsPanelId} aria-expanded={notificationsOpen} aria-haspopup="dialog" className="relative rounded-lg border border-line bg-surface p-2.5 text-ink-2 hover:border-line-strong hover:bg-surface-2" onClick={() => setNotificationsOpen((open) => !open)} type="button" aria-label="Notificações">
                  <Icon name="bell" />
                  {(notificationResource.data?.unread || 0) > 0 && <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-warn ring-2 ring-bg" />}
                </button>
                {notificationsOpen && (
                  <div className="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-card border border-line bg-surface p-2 shadow-lg" id={notificationsPanelId} role="dialog" aria-label="Notificações recentes">
                    <p className="px-3 py-2 text-xs font-semibold text-muted">Notificações</p>
                    {(notificationResource.data?.items || []).length === 0 && <p className="px-3 py-6 text-center text-sm text-muted">Nenhuma notificação.</p>}
                    {(notificationResource.data?.items || []).slice(0, 6).map((notification) => (
                      <button className={`w-full rounded-lg px-3 py-3 text-left hover:bg-ink/5 ${notification.is_read ? 'opacity-60' : ''}`} key={notification.id} onClick={() => openNotification(notification)} type="button">
                        <p className="text-sm font-semibold text-ink-2">{notification.title}</p>
                        <p className="mt-1 line-clamp-2 text-xs text-muted">{notification.message}</p>
                      </button>
                    ))}
                  </div>
                )}
              </div>
              <button className="rounded-lg border border-line bg-surface p-2.5 text-ink-2 hover:bg-danger-bg hover:text-danger" onClick={() => guardLeave(onLogout)} type="button" aria-label="Sair">
                <Icon name="logout" />
              </button>
            </div>
          </div>
        </header>
        <main className="mx-auto w-full min-w-0 max-w-[1600px] px-4 pb-28 pt-6 outline-none sm:px-6 sm:pb-6 lg:px-8" id="conteudo" tabIndex={-1}>{children}</main>
      </div>
      <nav aria-label="Atalhos" className="tabbar sm:hidden" data-tabbar="">
        {TABBAR.filter((item) => !item.permission || permissions.has(item.permission)).map((item) => (
          <button aria-current={path === item.path ? 'page' : undefined} className="tabbar-item" key={item.path} onClick={() => goTo(item.path)} type="button">
            <Icon name={item.icon} className="h-5 w-5" />
            <span>{item.label}</span>
          </button>
        ))}
        <button aria-expanded={mobileOpen} aria-haspopup="dialog" className="tabbar-item" onClick={() => setMobileOpen(true)} type="button">
          <Icon name="menu" className="h-5 w-5" />
          <span>Menu</span>
        </button>
      </nav>
    </div>
  )
}
