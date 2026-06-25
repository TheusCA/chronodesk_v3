import { useEffect, useMemo, useState } from 'react'
import { post } from '../lib/api'
import { navigation, pageMetadata } from '../lib/navigation'
import { useResource } from '../hooks/useResource'
import { Icon } from './ui/Icon'
import { BrandMark } from './BrandMark'
import { StatusDot } from './ui/Primitives'

function userLabel(session) {
  return session.ci.autenticado
    ? session.ci.nome
    : session.gestor.username || 'Usuário'
}

function Clock() {
  const [now, setNow] = useState(new Date())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 1000)
    return () => window.clearInterval(timer)
  }, [])
  return (
    <time className="hidden text-right text-xs text-slate-400 xl:block">
      {new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'medium' }).format(now)}
    </time>
  )
}

export function PortalLayout({ session, path, navigate, onLogout, children }) {
  const [mobileOpen, setMobileOpen] = useState(false)
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const notificationsPanelId = 'portal-notifications-panel'
  const notificationResource = useResource('portal/notifications.php')
  const refreshNotifications = notificationResource.refresh
  const permissions = useMemo(() => new Set(session.permissions || []), [session.permissions])
  const currentUserLabel = userLabel(session)
  const userInitial = currentUserLabel.trim().slice(0, 1).toUpperCase() || 'S'
  const metadata = pageMetadata[path] || ['Portal Operacional', 'Módulo do Portal SDK.']

  useEffect(() => {
    const timer = window.setInterval(() => refreshNotifications(), 60000)
    return () => window.clearInterval(timer)
  }, [refreshNotifications])

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

  function navContent(compact = false, showCollapseToggle = false) {
    return (
    <>
      <div className={`relative flex items-center border-b border-white/5 ${compact ? 'h-24 flex-col justify-center gap-2 px-2' : 'h-20 gap-3 px-5'}`}>
        <div className="rounded-card border border-cyan-400/15 bg-cyan-500/10 p-1.5">
          <BrandMark className="h-10 w-10 shrink-0 drop-shadow-[0_8px_18px_rgba(14,165,233,0.18)]" />
        </div>
        {!compact && <div className="min-w-0">
          <p className="truncate font-bold tracking-tight text-white">Portal SDK</p>
          <p className="truncate text-xs text-slate-500">Centro Operacional de TI</p>
        </div>}
        {showCollapseToggle && (
          <button
            aria-expanded={!compact}
            aria-label={compact ? 'Expandir menu lateral' : 'Minimizar menu lateral'}
            className={`rounded-lg border border-white/10 bg-slate-900/70 p-2 text-slate-400 transition hover:border-cyan-400/20 hover:bg-slate-800 hover:text-slate-100 ${compact ? '' : 'ml-auto'}`}
            onClick={() => setSidebarCollapsed((collapsed) => !collapsed)}
            title={compact ? 'Expandir menu' : 'Minimizar menu'}
            type="button"
          >
            <Icon name="menu" className="h-4 w-4" />
          </button>
        )}
      </div>
      <nav className="sidebar-scroll flex-1 space-y-1 overflow-y-auto px-3 py-5" aria-label="Módulos do portal">
        {navigation.map((item, index) => {
          if (item.section) {
            if (compact) {
              return <div className="my-3 h-px bg-white/5" key={`${item.section}-${index}`} aria-hidden="true" />
            }
            return <p className="px-3 pb-2 pt-5 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-600" key={`${item.section}-${index}`}>{item.section}</p>
          }
          if (item.permission && !permissions.has(item.permission)) return null
          const active = path === item.path
          return (
            <button
              className={`sidebar-link ${compact ? 'justify-center px-2' : ''} ${active ? 'sidebar-link-active' : ''}`}
              key={item.path}
              onClick={() => goTo(item.path)}
              title={compact ? item.label : undefined}
              type="button"
            >
              <span className={`grid h-8 w-8 place-items-center rounded-lg border ${active ? 'border-cyan-400/20 bg-cyan-500/10 text-cyan-200' : 'border-white/5 bg-slate-950/30 text-slate-500'}`}>
                <Icon name={item.icon} className="h-[17px] w-[17px]" />
              </span>
              {!compact && <span className="min-w-0 flex-1 truncate">{item.label}</span>}
              {active && !compact && <span className="h-1.5 w-1.5 rounded-full bg-cyan-300" aria-hidden="true" />}
            </button>
          )
        })}
      </nav>
      <div className={`border-t border-white/5 ${compact ? 'p-2' : 'p-4'}`} title={compact ? 'Desenvolvido por Matheus Camargo' : undefined}>
        <div className={`rounded-card border border-white/5 bg-slate-900/80 ${compact ? 'grid place-items-center p-2' : 'p-3'}`} title={compact ? currentUserLabel : undefined}>
          {compact ? (
            <span className="grid h-9 w-9 place-items-center rounded-lg bg-cyan-500/10 text-sm font-black text-cyan-100">{userInitial}</span>
          ) : (
            <>
              <p className="truncate text-sm font-semibold text-slate-200">{currentUserLabel}</p>
              <p className="mt-0.5 text-xs capitalize text-slate-500">{session.role?.replace('_', ' ')}</p>
            </>
          )}
        </div>
        {!compact && <p className="mt-3 text-center text-[11px] font-medium text-slate-600">Desenvolvido por Matheus Camargo</p>}
      </div>
    </>
    )
  }

  return (
    <div className="portal-tech-shell min-h-screen bg-chrono-bg">
      <aside className={`fixed inset-y-0 left-0 z-40 hidden border-r border-white/5 bg-chrono-sidebar transition-[width] duration-200 lg:flex lg:flex-col ${sidebarCollapsed ? 'w-20' : 'w-64'}`}>
        {navContent(sidebarCollapsed, true)}
      </aside>
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button className="absolute inset-0 bg-black/70" aria-label="Fechar menu" onClick={() => setMobileOpen(false)} type="button" />
          <aside className="relative flex h-full w-72 flex-col border-r border-white/10 bg-chrono-sidebar" role="dialog" aria-label="Menu principal" aria-modal="true">
            <button className="absolute right-3 top-3 rounded-lg p-2 text-slate-400" onClick={() => setMobileOpen(false)} type="button" aria-label="Fechar menu">
              <Icon name="close" />
            </button>
            {navContent()}
          </aside>
        </div>
      )}

      <div className={`min-w-0 transition-[padding] duration-200 ${sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64'}`}>
        <header className="sticky top-0 z-30 border-b border-white/5 bg-chrono-bg/95 backdrop-blur-xl">
          <div className="flex min-h-20 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
            <div className="flex min-w-0 items-center gap-3">
              <button className="rounded-lg border border-white/10 bg-slate-900/70 p-2 text-slate-300 lg:hidden" onClick={() => setMobileOpen(true)} type="button" aria-label="Abrir menu">
                <Icon name="menu" />
              </button>
              <div className="min-w-0">
                <p className="hidden text-[10px] font-bold uppercase tracking-[0.18em] text-cyan-300/70 sm:block">Portal SDK</p>
                <h1 className="truncate text-lg font-bold text-white sm:text-xl">{metadata[0]}</h1>
                <p className="hidden truncate text-xs text-slate-500 sm:block">{metadata[1]}</p>
              </div>
            </div>
            <div className="flex items-center gap-2 sm:gap-3">
              <div className="hidden md:block"><StatusDot tone="success" label="Operação online" /></div>
              <Clock />
              <div className="relative">
                <button aria-controls={notificationsPanelId} aria-expanded={notificationsOpen} aria-haspopup="dialog" className="relative rounded-lg border border-white/10 bg-slate-900/70 p-2.5 text-slate-300 hover:border-cyan-400/20 hover:bg-slate-800" onClick={() => setNotificationsOpen((open) => !open)} type="button" aria-label="Notificações">
                  <Icon name="bell" />
                  {(notificationResource.data?.unread || 0) > 0 && <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-amber-400 ring-2 ring-chrono-bg" />}
                </button>
                {notificationsOpen && (
                  <div className="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-card border border-white/10 bg-slate-900 p-2 shadow-2xl" id={notificationsPanelId} role="dialog" aria-label="Notificações recentes">
                    <p className="px-3 py-2 text-xs font-bold uppercase tracking-wider text-slate-500">Notificações</p>
                    {(notificationResource.data?.items || []).length === 0 && <p className="px-3 py-6 text-center text-sm text-slate-500">Nenhuma notificação.</p>}
                    {(notificationResource.data?.items || []).slice(0, 6).map((notification) => (
                      <button className={`w-full rounded-lg px-3 py-3 text-left hover:bg-white/5 ${notification.is_read ? 'opacity-60' : ''}`} key={notification.id} onClick={() => openNotification(notification)} type="button">
                        <p className="text-sm font-semibold text-slate-200">{notification.title}</p>
                        <p className="mt-1 line-clamp-2 text-xs text-slate-500">{notification.message}</p>
                      </button>
                    ))}
                  </div>
                )}
              </div>
              <button className="rounded-lg border border-white/10 bg-slate-900/70 p-2.5 text-slate-300 hover:bg-red-500/10 hover:text-red-300" onClick={onLogout} type="button" aria-label="Sair">
                <Icon name="logout" />
              </button>
            </div>
          </div>
        </header>
        <main className="mx-auto w-full min-w-0 max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">{children}</main>
      </div>
    </div>
  )
}
