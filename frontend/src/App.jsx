import { lazy, Suspense, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { Feedback } from './components/Feedback'
import { PortalLayout } from './components/PortalLayout'
import { RouteErrorBoundary } from './components/RouteErrorBoundary'
import { ConfirmActionDialog } from './components/ui/ConfirmActionDialog'
import { LoadingState } from './components/ui/States'
import { LoginPage } from './pages/LoginPage'
import { api, post, setCsrfToken } from './lib/api'
import { FEEDBACK_DURATION_MS } from './lib/actionFeedback'
import { navigation } from './lib/navigation'
import { useRouter } from './lib/router'
import { useLivePauses } from './hooks/useLivePauses'
import { hasPermission } from './lib/roles'

const lazyPage = (loader, exportName) => lazy(() => loader().then((module) => ({ default: module[exportName] })))

const AbsencesPage = lazyPage(() => import('./pages/AbsencesPage'), 'AbsencesPage')
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
const TechniciansPage = lazyPage(() => import('./pages/TechniciansPage'), 'TechniciansPage')

// [EE5] Ajuda do campo de justificativa de derrubar pausa (vai para a auditoria).
const FORCE_END_NOTE_HELP = 'Não inclua informações pessoais ou de saúde.'

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
  const feedbackSequence = useRef(0)
  const [forceEndTarget, setForceEndTarget] = useState(null)
  const [forceEndNote, setForceEndNote] = useState('')
  const [forceEndBusy, setForceEndBusy] = useState(false)
  const forceEndReturnRef = useRef(null)
  const forceEndHelpId = useId()
  const authenticated = session.ci.autenticado || session.gestor.autenticado
  const livePauses = useLivePauses({ enabled: authenticated })
  const status = livePauses.status

  const notify = useCallback((message, type = 'success') => {
    if (feedbackTimer.current) window.clearTimeout(feedbackTimer.current)
    // O id troca a cada aviso: a mesma mensagem repetida entra de novo na
    // região viva e é lida outra vez pelo leitor de tela.
    feedbackSequence.current += 1
    setFeedback({ message, type, id: feedbackSequence.current })
    feedbackTimer.current = window.setTimeout(() => {
      setFeedback(null)
      feedbackTimer.current = null
    }, FEEDBACK_DURATION_MS[type] ?? FEEDBACK_DURATION_MS.success)
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

  // [17f, DD3] Derrubar pausa pelo ConfirmActionDialog (antes, confirmação e
  // pergunta nativas do navegador). O payload é o de antes; a justificativa continua opcional.
  function forceEndBreak(employee) {
    if (!hasPermission(session, 'pausas.force_end') || !employee?.em_pausa) return
    forceEndReturnRef.current = document.activeElement
    setForceEndNote('')
    setForceEndTarget(employee)
  }

  // Sessão encerrada ou expirada (401) com o diálogo aberto: o pedido não volta
  // sozinho depois de entrar de novo.
  useEffect(() => {
    if (!authenticated) setForceEndTarget(null)
  }, [authenticated])

  async function confirmForceEnd() {
    if (!forceEndTarget || forceEndBusy) return
    setForceEndBusy(true)
    const result = await runAction(() => post('portal/admin_force_end_break.php', {
      funcionario_id: Number(forceEndTarget.id),
      justificativa: forceEndNote,
    }))
    setForceEndBusy(false)
    // Recusa do servidor: o diálogo fica aberto, com o aviso por cima, e o painel
    // atualiza por trás (a pausa pode ter sido encerrada por outra pessoa).
    if (result) setForceEndTarget(null)
    else refreshStatus().catch(() => {})
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
  } else if (router.path === '/ausencias') {
    content = <AbsencesPage session={session} notify={notify} />
  } else if (router.path === '/tecnicos') {
    content = <TechniciansPage />
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

  let screen
  if (loading) {
    screen = <div className="grid min-h-screen place-items-center text-sm text-slate-400">Carregando Portal Operacional...</div>
  } else if (!authenticated) {
    screen = <LoginPage onLoginCI={loginCI} loading={actionLoading} />
  } else {
    screen = (
      <PortalLayout session={session} path={router.path} navigate={router.navigate} onLogout={logout}>
        <RouteErrorBoundary resetKey={router.path}>
          <Suspense fallback={<LoadingState label="Carregando módulo..." />}>
            {content}
          </Suspense>
        </RouteErrorBoundary>
        {forceEndTarget && (
          <ConfirmActionDialog
            busy={forceEndBusy}
            busyLabel="Derrubando..."
            confirmLabel="Derrubar pausa"
            description={`Deseja derrubar a pausa de ${forceEndTarget.nome}? Esta ação não pode ser desfeita pelo sistema.`}
            onCancel={() => setForceEndTarget(null)}
            onConfirm={confirmForceEnd}
            returnFocusRef={forceEndReturnRef}
            title="Derrubar pausa?"
          >
            <label className="label mt-4 block">
              Justificativa da derrubada (opcional)
              {/* [EE5] O texto vai para a auditoria: até virar lista fechada (depois do deploy), só a ajuda. */}
              <textarea aria-describedby={forceEndHelpId} className="field mt-2 min-h-20" disabled={forceEndBusy} maxLength={500} value={forceEndNote} onChange={(event) => setForceEndNote(event.target.value)} />
            </label>
            {/* Fora do label: a ajuda é descrição do campo, não parte do nome dele. */}
            <p className="mt-1 text-xs text-slate-400" id={forceEndHelpId}>{FORCE_END_NOTE_HELP}</p>
          </ConfirmActionDialog>
        )}
      </PortalLayout>
    )
  }

  // [17f, DD2] O Feedback fica montado o tempo todo (carregando, login e portal):
  // as regiões vivas já existem quando o aviso chega, e o leitor de tela o lê.
  return (
    <>
      {screen}
      <Feedback feedback={feedback} onClose={closeFeedback} />
    </>
  )
}
