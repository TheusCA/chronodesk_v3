import { useMemo, useState } from 'react'
import { useResource } from '../hooks/useResource'
import { apiUrl } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'

const modules = {
  '/calendario': { endpoint: 'portal/calendar.php', noun: 'evento', columns: ['title', 'type', 'starts_at', 'status'] },
  '/tecnicos': { endpoint: 'portal/technicians.php', noun: 'técnico', columns: ['name', 'team', 'shift', 'status'] },
  '/escala-turnos': { endpoint: 'portal/schedules.php?type=turno', noun: 'turno', columns: ['employee_name', 'team', 'schedule_type', 'starts_at'] },
  '/escala-presencial': { endpoint: 'portal/schedules.php?type=presencial', noun: 'escala presencial', columns: ['employee_name', 'team', 'schedule_type', 'starts_at'] },
  '/ausencias': { endpoint: 'portal/absences.php', noun: 'ausência', columns: ['employee_name', 'absence_type', 'starts_on', 'status'] },
  '/horas-extras': { endpoint: 'portal/overtime.php', noun: 'hora extra', columns: ['employee_name', 'work_date', 'minutes', 'status'] },
  '/correcao-ponto': { endpoint: 'portal/time_corrections.php', noun: 'correção', columns: ['employee_name', 'correction_date', 'reason', 'status'] },
  '/avisos': { endpoint: 'portal/announcements.php', noun: 'aviso', columns: ['title', 'category', 'severity', 'created_at'] },
  '/plantonistas': { endpoint: 'portal/oncall.php', noun: 'plantonista', columns: ['employee_name', 'team', 'starts_at', 'ends_at'] },
  '/sobreavisos': { endpoint: 'portal/standby.php', noun: 'sobreaviso', columns: ['employee_name', 'team', 'starts_at', 'ends_at'] },
  '/documentacao': { endpoint: 'portal/documents.php', noun: 'documento', columns: ['title', 'document_type', 'author', 'updated_at'] },
  '/integracoes': { endpoint: 'portal/integrations.php', noun: 'integração', columns: ['name', 'status', 'configured'] },
}

const labels = {
  title: 'Título', type: 'Tipo', starts_at: 'Início', ends_at: 'Fim', status: 'Status',
  name: 'Nome', team: 'Equipe', shift: 'Turno', employee_name: 'Colaborador',
  schedule_type: 'Escala', absence_type: 'Tipo', starts_on: 'Início', work_date: 'Data',
  minutes: 'Minutos', correction_date: 'Data', reason: 'Motivo', category: 'Categoria',
  severity: 'Severidade', created_at: 'Criado em', document_type: 'Tipo', author: 'Autor',
  updated_at: 'Atualizado em', configured: 'Configurada',
}

function valueFor(key, value) {
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não'
  if (key.includes('_on') || key === 'work_date' || key === 'correction_date') return formatDate(value)
  if (key.includes('_at')) return formatDateTime(value)
  return value ?? 'Não informado'
}

export function ModulePage({ path }) {
  const definition = modules[path]
  const resource = useResource(definition.endpoint)
  const [search, setSearch] = useState('')
  const items = useMemo(() => {
    const term = search.trim().toLowerCase()
    return (resource.data?.items || []).filter((item) => (
      !term || definition.columns.some((key) => String(item[key] ?? '').toLowerCase().includes(term))
    ))
  }, [definition.columns, resource.data, search])

  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  return (
    <div className="space-y-5">
      <section className="card flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 className="font-bold text-white">Base operacional</h2><p className="text-sm text-slate-500">Estrutura preparada para persistência e integrações futuras.</p></div>
        <input className="field sm:max-w-xs" placeholder={`Buscar ${definition.noun}`} value={search} onChange={(event) => setSearch(event.target.value)} />
      </section>
      {items.length === 0 ? <EmptyState title={`Nenhum ${definition.noun} cadastrado`} description="A migration cria a estrutura segura; os dados serão exibidos aqui sem mocks fixos." /> : (
        <section className="card table-wrap">
          <table className="data-table">
            <thead><tr>{definition.columns.map((key) => <th key={key}>{labels[key] || key}</th>)}</tr></thead>
            <tbody>{items.map((item, index) => <tr key={item.id || index}>{definition.columns.map((key) => <td key={key}>{valueFor(key, item[key])}</td>)}</tr>)}</tbody>
          </table>
        </section>
      )}
    </div>
  )
}

export function ReportsPage() {
  const resource = useResource('portal/reports.php')
  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />
  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      {(resource.data?.reports || []).map((report) => (
        <article className="card" key={report.key}>
          <h2 className="font-bold text-white">{report.name}</h2>
          <p className="mt-2 text-sm text-slate-500">{report.description}</p>
          <a className="btn-secondary mt-5" href={apiUrl(report.download_url)}>Baixar relatório</a>
        </article>
      ))}
    </div>
  )
}

export function SettingsPage({ session }) {
  return (
    <div className="grid gap-5 md:grid-cols-2">
      <section className="card">
        <p className="text-sm text-slate-500">Perfil ativo</p>
        <h2 className="mt-2 text-xl font-bold capitalize text-white">{session.role?.replace('_', ' ')}</h2>
        <p className="mt-3 text-sm text-slate-400">As permissões são calculadas no backend e refletidas na navegação.</p>
      </section>
      <section className="card">
        <p className="text-sm text-slate-500">Notificações</p>
        <h2 className="mt-2 text-xl font-bold text-white">Polling seguro</h2>
        <p className="mt-3 text-sm text-slate-400">O sino consulta notificações internas sem expor tokens ou segredos no navegador.</p>
      </section>
    </div>
  )
}
