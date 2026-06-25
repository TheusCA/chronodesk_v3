import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Feedback } from './components/Feedback'
import { PortalLayout } from './components/PortalLayout'
import { RouteErrorBoundary } from './components/RouteErrorBoundary'
import { LoadingState } from './components/ui/States'
import { LoginPage } from './pages/LoginPage'
import { api, post, setCsrfToken } from './lib/api'
import { navigation } from './lib/navigation'
import { useRouter } from './lib/router'
import { useLivePauses } from './hooks/useLivePauses'

const lazyPage = (loader, exportName) => lazy(() => loader().then((module) => ({ default: module[exportName] })))

const AdminPage = lazyPage(() => import('./pages/AdminPage'), 'AdminPage')
const DashboardPage = lazyPage(() => import('./pages/DashboardPage'), 'DashboardPage')
const DocumentsPage = lazyPage(() => import('./pages/DocumentsPage'), 'DocumentsPage')
const CriticalIncidentsPage = lazyPage(() => import('./pages/CriticalIncidentsPage'), 'CriticalIncidentsPage')
const CalendarPage = lazyPage(() => import('./pages/CalendarPage'), 'CalendarPage')
const ShiftSchedulesPage = lazyPage(() => import('./pages/ShiftSchedulesPage'), 'ShiftSchedulesPage')
const MetricasPage = lazyPage(() => import('./pages/MetricasPage'), 'MetricasPage')
const ModulePage = lazyPage(() => import('./pages/ModulePage'), 'ModulePage')
const SettingsPage = lazyPage(() => import('./pages/ModulePage'), 'SettingsPage')
const PaMapPage = lazyPage(() => import('./pages/PaMapPage'), 'PaMapPage')
const OncallPage = lazyPage(() => import('./pages/OperationalPages'), 'OncallPage')
const OperationalReportsPage = lazyPage(() => import('./pages/OperationalPages'), 'OperationalReportsPage')
const OvertimePage = lazyPage(() => import('./pages/OperationalPages'), 'OvertimePage')
const SchedulePage = lazyPage(() => import('./pages/OperationalPages'), 'SchedulePage')
const TimeCorrectionPage = lazyPage(() => import('./pages/OperationalPages'), 'TimeCorrectionPage')
const PausasPage = lazyPage(() => import('./pages/PausasPage'), 'PausasPage')

const emptySession = {
  role: null,
  permissions: [],
  ci: { autenticado: false, funcionario_id: 0, nome: '', username: '' },
  gestor: { autenticado: false, admin: false, username: '', role: null },
}

function AccessDenied({ navigate }) {
  return (
    <div className="card mx-auto max-w-xl border-red-500/30 text-center">
      <h2 className="text-xl font-bold text-white">Acesso não autorizado</h2>
      <p className="mt-2 text-sm text-slate-400">Seu perfil não possui permissão para acessar este módulo.</p>
      <button className="btn-secondary mt-5" onClick={() => navigate('/dashboard')} type="button">Voltar ao dashboard</button>
    </div>
  )
}

export default function App() {
  const router = useRouter()
  const [session, setSession] = useState(emptySession)
  const [loading, setLoading] = useState(true)
  const [actionLoading, setActionLoading] = useState(false)
  const [feedback, setFeedback] = useState(null)
  const feedbackTimer = useRef(null)
  const authenticated = session.ci.autenticado || session.gestor.autenticado
  const livePauses = useLivePauses({ enabled: authenticated })
  const status = livePauses.status

  const notify = useCallback((message, type = 'success') => {
    if (feedbackTimer.current) window.clearTimeout(feedbackTimer.current)
    setFeedback({ message, type })
    feedbackTimer.current = window.setTimeout(() => {
      setFeedback(null)
      feedbackTimer.current = null
    }, 5000)
  }, [])

  useEffect(() => () => {
    if (feedbackTimer.current) window.clearTimeout(feedbackTimer.current)
  }, [])

  const refreshStatus = livePauses.refresh

  const refreshSession = useCallback(async () => {
    const data = await api('session.php')
    setCsrfToken(data.csrf_token)
    setSession({
      role: data.role,
      permissions: data.permissions || [],
      ci: data.ci,
      gestor: data.gestor,
    })
    return data
  }, [])

  useEffect(() => {
    refreshSession()
      .catch((error) => notify(error.message, 'error'))
      .finally(() => setLoading(false))
  }, [notify, refreshSession])

  useEffect(() => {
    const handleUnauthorized = () => refreshSession().catch(() => setSession(emptySession))
    window.addEventListener('chronodesk:unauthorized', handleUnauthorized)
    return () => window.removeEventListener('chronodesk:unauthorized', handleUnauthorized)
  }, [refreshSession])

  async function runAction(callback, { refresh = true } = {}) {
    setActionLoading(true)
    try {
      const result = await callback()
      notify(result.mensagem || 'Operação concluída.')
      if (refresh) await refreshStatus()
      return result
    } catch (error) {
      if (error.status === 401) await refreshSession().catch(() => {})
      notify(error.message, 'error')
      return null
    } finally {
      setActionLoading(false)
    }
  }

  async function loginCI(credentials) {
    return runAction(async () => {
      const result = await post('login_ci.php', credentials)
      await refreshSession()
      router.navigate('/pausas')
      return result
    })
  }

  async function logout() {
    await runAction(async () => {
      const result = await post('logout.php', {})
      setCsrfToken(result.csrf_token)
      setSession(emptySession)
      router.navigate('/dashboard', { replace: true })
      return result
    }, { refresh: false })
  }

  const permissions = useMemo(() => new Set(session.permissions), [session.permissions])
  const route = navigation.find((item) => item.path === router.path)
  const allowed = !route?.permission || permissions.has(route.permission)
  const funcionarioId = session.ci.funcionario_id

  async function forceEndBreak(employee) {
    if (session.role !== 'admin' || !employee?.em_pausa) return null
    if (!window.confirm(`Tem certeza que deseja derrubar a pausa de ${employee.nome}?`)) return null
    const justificativa = window.prompt('Justificativa da derrubada (opcional):', '') || ''
    return runAction(() => post('portal/admin_force_end_break.php', {
      funcionario_id: Number(employee.id),
      justificativa,
    }))
  }

  const closeFeedback = useCallback(() => {
    if (feedbackTimer.current) {
      window.clearTimeout(feedbackTimer.current)
      feedbackTimer.current = null
    }
    setFeedback(null)
  }, [])

  let content
  if (!allowed) {
    content = <AccessDenied navigate={router.navigate} />
  } else if (router.path === '/dashboard') {
    content = <DashboardPage navigate={router.navigate} session={session} livePauses={livePauses} loading={actionLoading} onForceEndBreak={forceEndBreak} />
  } else if (router.path === '/pausas') {
    content = (
      <PausasPage
        session={session}
        status={status}
        livePauses={livePauses}
        loading={actionLoading}
        onStart={(reason) => runAction(() => post('iniciar_pausa.php', { funcionario_id: funcionarioId, motivo_pausa: reason }))}
        onRequest={(reason, observation) => runAction(() => post('solicitar_pausa_com_aprovacao.php', { funcionario_id: funcionarioId, motivo_pausa: reason, observacao: observation }))}
        onFinish={() => runAction(() => post('finalizar_pausa.php', { funcionario_id: funcionarioId }))}
        onForceEndBreak={forceEndBreak}
      />
    )
  } else if (router.path === '/admin') {
    content = <AdminPage session={session} search={router.search} navigate={router.navigate} notify={notify} refreshStatus={refreshStatus} />
  } else if (router.path === '/metricas') {
    content = <MetricasPage />
  } else if (router.path === '/calendario') {
    content = <CalendarPage session={session} notify={notify} />
  } else if (router.path === '/escala-presencial') {
    content = <SchedulePage session={session} notify={notify} />
  } else if (router.path === '/mapa-pa') {
    content = <PaMapPage notify={notify} />
  } else if (router.path === '/escala-turnos') {
    content = <ShiftSchedulesPage session={session} notify={notify} />
  } else if (router.path === '/horas-extras') {
    content = <OvertimePage session={session} notify={notify} />
  } else if (router.path === '/correcao-ponto') {
    content = <TimeCorrectionPage session={session} notify={notify} />
  } else if (router.path === '/plantonistas') {
    content = <OncallPage session={session} notify={notify} />
  } else if (router.path === '/chamados-criticos') {
    content = <CriticalIncidentsPage session={session} notify={notify} />
  } else if (router.path === '/relatorios') {
    content = <OperationalReportsPage />
  } else if (router.path === '/documentacao') {
    content = <DocumentsPage notify={notify} />
  } else if (router.path === '/configuracoes') {
    content = <SettingsPage session={session} />
  } else if (route) {
    content = <ModulePage path={router.path} />
  } else {
    content = <AccessDenied navigate={router.navigate} />
  }

  if (loading) {
    return <div className="grid min-h-screen place-items-center text-sm text-slate-400">Carregando Portal Operacional...</div>
  }

  if (!authenticated) {
    return (
      <>
        <LoginPage onLoginCI={loginCI} loading={actionLoading} />
        <Feedback feedback={feedback} onClose={closeFeedback} />
      </>
    )
  }

  return (
    <PortalLayout session={session} path={router.path} navigate={router.navigate} onLogout={logout}>
      <RouteErrorBoundary resetKey={router.path}>
        <Suspense fallback={<LoadingState label="Carregando mÃ³dulo..." />}>
          {content}
        </Suspense>
      </RouteErrorBoundary>
      <Feedback feedback={feedback} onClose={closeFeedback} />
    </PortalLayout>
  )
}
