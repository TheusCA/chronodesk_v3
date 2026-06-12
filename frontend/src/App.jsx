import { useCallback, useEffect, useState } from 'react'
import { AdminDashboard } from './components/AdminDashboard'
import { AppLayout } from './components/AppLayout'
import { DashboardPausas } from './components/DashboardPausas'
import { Feedback } from './components/Feedback'
import { LoginAdmin } from './components/LoginAdmin'
import { LoginCI } from './components/LoginCI'
import { MetricasDashboard } from './components/MetricasDashboard'
import { api, post, setCsrfToken } from './lib/api'

const emptySession = {
  ci: { autenticado: false, funcionario_id: 0, nome: '', username: '' },
  gestor: { autenticado: false, admin: false, username: '' },
}

export default function App() {
  const [session, setSession] = useState(emptySession)
  const [status, setStatus] = useState({ n1: [], n2: [] })
  const [requests, setRequests] = useState([])
  const [metrics, setMetrics] = useState({})
  const [activeView, setActiveView] = useState('pausas')
  const [loading, setLoading] = useState(true)
  const [actionLoading, setActionLoading] = useState(false)
  const [feedback, setFeedback] = useState(null)

  const notify = useCallback((message, type = 'success') => {
    setFeedback({ message, type })
    window.setTimeout(() => setFeedback(null), 5000)
  }, [])

  const refreshStatus = useCallback(async () => {
    setStatus(await api('../api/status.php'))
  }, [])

  const refreshSession = useCallback(async () => {
    const data = await api('../api/session.php')
    setCsrfToken(data.csrf_token)
    setSession({ ci: data.ci, gestor: data.gestor })
    return data
  }, [])

  const refreshAdmin = useCallback(async () => {
    const [requestData, metricData] = await Promise.all([
      api('../api/solicitacoes_pendentes.php'),
      api('../api/metricas.php'),
    ])
    setRequests(requestData.solicitacoes || [])
    setMetrics(metricData)
  }, [])

  useEffect(() => {
    Promise.all([refreshSession(), refreshStatus()])
      .catch((error) => notify(error.message, 'error'))
      .finally(() => setLoading(false))
  }, [notify, refreshSession, refreshStatus])

  useEffect(() => {
    if (session.gestor.autenticado) {
      refreshAdmin().catch((error) => notify(error.message, 'error'))
    }
  }, [notify, refreshAdmin, session.gestor.autenticado])

  useEffect(() => {
    const timer = window.setInterval(() => refreshStatus().catch(() => {}), 30000)
    return () => window.clearInterval(timer)
  }, [refreshStatus])

  async function runAction(callback, shouldRefresh = true) {
    setActionLoading(true)
    try {
      const result = await callback()
      notify(result.mensagem || 'Operação concluída.')
      if (shouldRefresh) {
        await Promise.all([refreshStatus(), session.gestor.autenticado ? refreshAdmin() : Promise.resolve()])
      }
      return result
    } catch (error) {
      if (error.status === 401) await refreshSession()
      notify(error.message, 'error')
      return null
    } finally {
      setActionLoading(false)
    }
  }

  async function loginCI(credentials) {
    return runAction(async () => {
      const result = await post('../api/login_ci.php', credentials)
      await refreshSession()
      return result
    })
  }

  async function loginAdmin(credentials) {
    return runAction(async () => {
      const result = await post('../api/login_admin.php', credentials)
      await refreshSession()
      setActiveView('admin')
      return result
    })
  }

  async function logout() {
    await runAction(async () => {
      const result = await post('../api/logout.php', {})
      setCsrfToken(result.csrf_token)
      setSession(emptySession)
      setActiveView('pausas')
      return result
    }, false)
  }

  const funcionarioId = session.ci.funcionario_id

  if (loading) {
    return <div className="grid min-h-screen place-items-center text-slate-400">Carregando ChronoDesk...</div>
  }

  let content
  if (activeView === 'admin' && session.gestor.autenticado) {
    content = <AdminDashboard requests={requests} loading={actionLoading} onApprove={(id) => runAction(() => post('../api/aprovar_pausa.php', { funcionario_id: id }))} onReject={(id) => runAction(() => post('../api/rejeitar_pausa.php', { funcionario_id: id }))} />
  } else if (activeView === 'metricas' && session.gestor.autenticado) {
    content = <MetricasDashboard metrics={metrics} />
  } else if (session.ci.autenticado) {
    content = (
      <DashboardPausas
        session={session}
        status={status}
        actionLoading={actionLoading}
        onStart={(motivo) => runAction(() => post('../api/iniciar_pausa.php', { funcionario_id: funcionarioId, motivo_pausa: motivo }))}
        onRequest={(motivo, observacao) => runAction(() => post('../api/solicitar_pausa_com_aprovacao.php', { funcionario_id: funcionarioId, motivo_pausa: motivo, observacao }))}
        onFinish={() => runAction(() => post('../api/finalizar_pausa.php', { funcionario_id: funcionarioId }))}
      />
    )
  } else {
    content = (
      <div className="mx-auto grid max-w-4xl gap-5 md:grid-cols-2">
        <LoginCI onSubmit={loginCI} loading={actionLoading} />
        <LoginAdmin onSubmit={loginAdmin} loading={actionLoading} />
      </div>
    )
  }

  return (
    <AppLayout session={session} activeView={activeView} onViewChange={setActiveView} onLogout={logout}>
      {content}
      <Feedback feedback={feedback} />
    </AppLayout>
  )
}
