import { useMemo, useState } from 'react'
import { useResource } from '../hooks/useResource'
import { apiUrl } from '../lib/api'
import { formatDateTime, formatDuration, parseDate } from '../lib/format'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'

function periodStart(period) {
  const now = new Date()
  if (period === 'today') return new Date(now.getFullYear(), now.getMonth(), now.getDate())
  if (period === '7d') return new Date(now.getTime() - 7 * 86400000)
  if (period === '30d') return new Date(now.getTime() - 30 * 86400000)
  if (period === 'month') return new Date(now.getFullYear(), now.getMonth(), 1)
  return null
}

function Indicator({ label, value, tone = 'text-white' }) {
  return <article className="card"><p className="text-sm text-slate-500">{label}</p><p className={`mt-3 text-2xl font-black ${tone}`}>{value}</p></article>
}

function Ranking({ title, values, formatter = (value) => value }) {
  const entries = Object.entries(values || {}).sort((a, b) => Number(b[1]) - Number(a[1])).slice(0, 8)
  return (
    <section className="card">
      <h3 className="mb-4 font-bold text-white">{title}</h3>
      {entries.length === 0 && <p className="text-sm text-slate-500">Sem dados no período.</p>}
      <div className="space-y-3">
        {entries.map(([label, value], index) => (
          <div className="flex items-center gap-3" key={label}>
            <span className="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-slate-800 text-xs font-bold text-slate-400">{index + 1}</span>
            <span className="min-w-0 flex-1 truncate text-sm text-slate-400">{label}</span>
            <strong className="text-sm text-slate-200">{formatter(value)}</strong>
          </div>
        ))}
      </div>
    </section>
  )
}

export function MetricasPage() {
  const resource = useResource('metricas.php')
  const [filters, setFilters] = useState({
    period: '30d', customFrom: '', customTo: '', team: '',
    employee: '', reason: '', status: '', search: '',
  })
  const [sort, setSort] = useState({ key: 'inicio_pausa', direction: 'desc' })

  const sourceRows = useMemo(() => {
    const completed = (resource.data?.pausas_detalhadas || []).map((row) => ({ ...row, is_request: false }))
    const decisions = (resource.data?.solicitacoes_reuniao || [])
      .filter((request) => request.status !== 'approved')
      .map((request) => ({
        id_funcionario: request.employee_id,
        nome_funcionario: request.employee_name,
        equipe: request.team,
        inicio_pausa: request.requested_at,
        fim_pausa: request.decided_at,
        duracao_segundos: 0,
        motivo_pausa: request.reason,
        status_aprovacao: request.status === 'pending' ? 'pendente' : 'rejeitado',
        observacao_reuniao: request.observation,
        excedeu_limite: false,
        is_request: true,
      }))
    return [...completed, ...decisions]
  }, [resource.data])

  const rows = useMemo(() => {
    const start = filters.period === 'custom' && filters.customFrom
      ? parseDate(`${filters.customFrom}T00:00:00`)
      : periodStart(filters.period)
    const end = filters.period === 'custom' && filters.customTo
      ? parseDate(`${filters.customTo}T23:59:59`)
      : null
    return [...sourceRows]
      .filter((row) => {
        const date = parseDate(row.inicio_pausa)
        const statusMatch = !filters.status
          || (filters.status === 'exceeded' && !row.is_request && row.excedeu_limite)
          || (filters.status === 'within' && !row.is_request && !row.excedeu_limite)
          || row.status_aprovacao === filters.status
        return (!start || date >= start)
          && (!end || date <= end)
          && (!filters.team || row.equipe === filters.team)
          && (!filters.employee || row.nome_funcionario === filters.employee)
          && (!filters.reason || row.motivo_pausa === filters.reason)
          && statusMatch
          && (!filters.search || `${row.nome_funcionario} ${row.equipe} ${row.motivo_pausa}`.toLowerCase().includes(filters.search.toLowerCase()))
      })
      .sort((a, b) => {
        const left = sort.key === 'duracao_segundos' ? Number(a[sort.key]) : String(a[sort.key] || '')
        const right = sort.key === 'duracao_segundos' ? Number(b[sort.key]) : String(b[sort.key] || '')
        return (left > right ? 1 : left < right ? -1 : 0) * (sort.direction === 'asc' ? 1 : -1)
      })
  }, [filters, sort, sourceRows])

  const summary = useMemo(() => {
    const completedRows = rows.filter((row) => !row.is_request)
    const totalSeconds = completedRows.reduce((total, row) => total + Number(row.duracao_segundos || 0), 0)
    const employees = {}
    const teams = {}
    const reasons = {}
    completedRows.forEach((row) => {
      employees[row.nome_funcionario] = (employees[row.nome_funcionario] || 0) + Number(row.duracao_segundos || 0)
      teams[row.equipe?.toUpperCase()] = (teams[row.equipe?.toUpperCase()] || 0) + 1
      reasons[row.motivo_pausa] = (reasons[row.motivo_pausa] || 0) + 1
    })
    const topEmployee = Object.entries(employees).sort((a, b) => b[1] - a[1])[0]
    const topTeam = Object.entries(teams).sort((a, b) => b[1] - a[1])[0]
    return {
      total: completedRows.length,
      totalSeconds,
      average: completedRows.length ? totalSeconds / completedRows.length : 0,
      exceeded: completedRows.filter((row) => row.excedeu_limite).length,
      topEmployee,
      topTeam,
      employees,
      teams,
      reasons,
    }
  }, [rows])

  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  const allRows = sourceRows
  const employees = [...new Set(allRows.map((row) => row.nome_funcionario))].sort()
  const reasons = [...new Set(allRows.map((row) => row.motivo_pausa))].sort()

  function changeSort(key) {
    setSort((current) => ({ key, direction: current.key === key && current.direction === 'desc' ? 'asc' : 'desc' }))
  }

  return (
    <div className="space-y-6">
      {resource.data?.dados_truncados && (
        <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-200">
          A análise está limitada aos {resource.data.limite_registros} registros mais recentes. Use um período menor para resultados precisos.
        </div>
      )}
      <section className="card">
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
          <select className="field" value={filters.period} onChange={(event) => setFilters({ ...filters, period: event.target.value })}>
            <option value="today">Hoje</option><option value="7d">Últimos 7 dias</option><option value="30d">Últimos 30 dias</option><option value="month">Mês atual</option><option value="custom">Intervalo personalizado</option><option value="all">Todo o histórico</option>
          </select>
          <select className="field" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })}><option value="">Todas as equipes</option><option value="n1">N1</option><option value="n2">N2</option></select>
          <select className="field" value={filters.employee} onChange={(event) => setFilters({ ...filters, employee: event.target.value })}><option value="">Todos os colaboradores</option>{employees.map((name) => <option key={name}>{name}</option>)}</select>
          <select className="field" value={filters.reason} onChange={(event) => setFilters({ ...filters, reason: event.target.value })}><option value="">Todos os motivos</option>{reasons.map((reason) => <option key={reason}>{reason}</option>)}</select>
          <select className="field" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">Todos os status</option><option value="within">Dentro do tempo</option><option value="exceeded">Excedidas</option><option value="pendente">Pendentes</option><option value="aprovado">Aprovadas</option><option value="rejeitado">Rejeitadas</option></select>
          <input className="field" placeholder="Buscar" value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} />
        </div>
        {filters.period === 'custom' && (
          <div className="mt-3 grid gap-3 sm:max-w-xl sm:grid-cols-2">
            <label className="label">Data inicial<input className="field mt-2" type="date" value={filters.customFrom} onChange={(event) => setFilters({ ...filters, customFrom: event.target.value })} /></label>
            <label className="label">Data final<input className="field mt-2" min={filters.customFrom} type="date" value={filters.customTo} onChange={(event) => setFilters({ ...filters, customTo: event.target.value })} /></label>
          </div>
        )}
      </section>

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Indicator label="Total de pausas" value={summary.total} tone="text-blue-300" />
        <Indicator label="Tempo total" value={formatDuration(summary.totalSeconds)} />
        <Indicator label="Tempo médio" value={formatDuration(summary.average)} />
        <Indicator label="Pausas excedidas" value={summary.exceeded} tone="text-red-300" />
        <Indicator label="Maior tempo acumulado" value={summary.topEmployee?.[0] || 'Sem dados'} tone="text-amber-300" />
        <Indicator label="Equipe com maior volume" value={summary.topTeam?.[0] || 'Sem dados'} tone="text-violet-300" />
        <Indicator label="Reuniões aprovadas" value={rows.filter((row) => !row.is_request && row.motivo_pausa === 'Reunião' && row.status_aprovacao === 'aprovado').length} />
        <Indicator label="Reuniões pendentes" value={rows.filter((row) => row.is_request && row.status_aprovacao === 'pendente').length} />
        <Indicator label="Reuniões rejeitadas" value={rows.filter((row) => row.is_request && row.status_aprovacao === 'rejeitado').length} tone="text-red-300" />
        <Indicator label="Registros filtrados" value={rows.length} tone="text-emerald-300" />
      </section>

      <section className="grid gap-5 lg:grid-cols-3">
        <Ranking title="Volume por equipe" values={summary.teams} />
        <Ranking title="Pausas por motivo" values={summary.reasons} />
        <Ranking title="Tempo por colaborador" values={summary.employees} formatter={formatDuration} />
      </section>

      <section className="card">
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
          <div><h2 className="font-bold text-white">Histórico detalhado</h2><p className="text-sm text-slate-500">Ordene pelas colunas de data ou duração.</p></div>
          <a className="btn-secondary" href={apiUrl('download_relatorio.php')}>Exportar relatório</a>
        </div>
        {rows.length === 0 ? <EmptyState title="Nenhuma pausa encontrada" description="Ajuste os filtros para consultar outro período." /> : (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th><button onClick={() => changeSort('inicio_pausa')} type="button">Data e hora</button></th><th>Colaborador</th><th>Equipe</th><th>Motivo</th><th><button onClick={() => changeSort('duracao_segundos')} type="button">Duração</button></th><th>Status</th></tr></thead>
              <tbody>{rows.map((row, index) => (
                <tr key={`${row.id_funcionario}-${row.inicio_pausa}-${index}`}>
                  <td>{formatDateTime(row.inicio_pausa)}</td>
                  <td><strong>{row.nome_funcionario}</strong></td>
                  <td>{row.equipe?.toUpperCase()}</td>
                  <td>{row.motivo_pausa}</td>
                  <td>{row.is_request ? '—' : formatDuration(row.duracao_segundos)}</td>
                  <td>
                    <span className={`status-badge ${row.status_aprovacao === 'pendente' ? 'status-warning' : row.status_aprovacao === 'rejeitado' || row.excedeu_limite ? 'status-danger' : 'status-success'}`}>
                      {row.status_aprovacao === 'pendente' ? 'Pendente' : row.status_aprovacao === 'rejeitado' ? 'Rejeitada' : row.excedeu_limite ? 'Excedida' : 'Dentro do tempo'}
                    </span>
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}
