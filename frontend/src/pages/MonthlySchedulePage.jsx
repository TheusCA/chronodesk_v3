import { useRef, useState } from 'react'
import { ConfirmActionDialog } from '../components/ui/ConfirmActionDialog'
import { Dialog } from '../components/ui/Dialog'
import { Field } from '../components/ui/Field'
import { InlineAlert } from '../components/ui/Primitives'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { useResource } from '../hooks/useResource'
import { apiUrl, post, postForm } from '../lib/api'
import { runAction } from '../lib/actionRunner'
import { toneClass } from '../lib/criticalGrid'
import {
  DIVERGENCE_LABELS, IMPORT_STATUS_LABELS, IMPORT_STEP_LABELS, IMPORT_STEPS,
  cellDetail, cellLabel, cellTone, shortDay, statusAlert, statusLabel, summaryRows, weekLabel, weeksOf,
} from '../lib/monthlySchedule'
import { hasPermission } from '../lib/roles'
import './criticalGrid/criticalGrid.css'

const ENDPOINT = 'portal/monthly_schedule.php'
const TEAM_ORDER = { n1: 0, n2: 1, critico: 2, hibrido: 3, lideranca: 4 }
const LEGEND = [
  { tone: 'papel-azul', label: 'Presencial' },
  { tone: 'papel-verde', label: 'Home' },
  { tone: 'ausente', label: 'Ausente' },
  { tone: 'banco', label: 'Sem PA' },
]

// Uma célula: situação e PA; ⇄ quando trocou de PA; ! quando diverge da presença de hoje.
function MonthCell({ cell, date, today, holiday, inCommonGroup }) {
  const detail = cellDetail(cell)
  const marks = [cell?.moved ? '⇄' : '', cell?.divergence ? '!' : ''].filter(Boolean).join(' ')
  const label = `${shortDay(date)}: ${cellLabel(cell, inCommonGroup)}${detail ? `, ${detail}` : ''}${cell?.moved ? ', trocou de PA' : ''}${cell?.divergence ? `, ${DIVERGENCE_LABELS[cell.divergence]}` : ''}${holiday ? ', feriado' : ''}`
  const classes = ['esc-cell', toneClass(cellTone(cell)), date === today ? 'esc-today' : '', marks ? 'esc-has-marker' : ''].filter(Boolean).join(' ')
  return (
    <div aria-label={label} className={classes} data-month-cell={date} role="img" title={cell?.divergence ? DIVERGENCE_LABELS[cell.divergence] : undefined}>
      <span className="esc-cell-label">{cellLabel(cell, inCommonGroup)}</span>
      {detail && <span className="esc-cell-detail">{detail}</span>}
      {marks && <span aria-hidden="true" className="esc-cell-marker">{marks}</span>}
    </div>
  )
}

function MonthWeek({ week, people, cells, today, holidays }) {
  return (
    <details className="esc-week" data-month-week={week.start} open>
      <summary><span>{weekLabel(week)}</span></summary>
      <div className={`esc-grid esc-grid-scroll${week.days.length < 5 ? ` esc-days-${week.days.length}` : ''}`} role="table" aria-label={weekLabel(week)}>
        <div className="esc-grid-head" role="columnheader">Técnico</div>
        {week.days.map((date) => (
          <div className={`esc-grid-head${date === today ? ' esc-today' : ''}`} key={date} role="columnheader">{shortDay(date)}{holidays.includes(date) ? ' · feriado' : ''}</div>
        ))}
        {people.map((person) => (
          <div className="esc-row" key={person.id} role="row">
            <div className="esc-grid-name" role="rowheader" title={person.name}>{person.name}</div>
            {week.days.map((date) => (
              <div key={date} role="cell">
                <MonthCell cell={cells[`${person.id}:${date}`]} date={date} holiday={holidays.includes(date)} inCommonGroup={person.team === 'n1' || person.team === 'n2'} today={today} />
              </div>
            ))}
          </div>
        ))}
      </div>
    </details>
  )
}

// RESUMO do script: indicadores e STATUS por dia.
function MonthSummary({ days, summary }) {
  const rows = summaryRows(days, summary)
  return (
    <section className="card space-y-3" data-month-summary="">
      <h2 className="font-semibold text-ink">Resumo por dia</h2>
      <div className="overflow-x-auto">
        <table className="esc-summary">
          <caption className="sr-only">Indicadores da escala por dia útil</caption>
          <thead><tr><th scope="col">Indicador</th>{days.map((day) => <th key={day} scope="col">{shortDay(day)}</th>)}</tr></thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.label}>
                <td>{row.label}</td>
                {row.values.map((value, index) => (
                  <td className={row.label === 'Status' && statusAlert(summary[days[index]]?.status) ? 'font-semibold text-danger' : ''} data-month-status={row.label === 'Status' ? days[index] : undefined} key={days[index]}>{value}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

// Importação da planilha Base (só admin): prévia obrigatória, passos escolhidos.
function BaseImportDialog({ month, onClose, notify, onDone }) {
  const [files, setFiles] = useState({ planilha: null, casamento: null })
  const [from, setFrom] = useState('')
  const [preview, setPreview] = useState(null)
  const [steps, setSteps] = useState([...IMPORT_STEPS])
  const [busy, setBusy] = useState(false)
  const ready = files.planilha && files.casamento && from
  async function send(action) {
    const body = new FormData()
    body.append('planilha', files.planilha)
    body.append('casamento', files.casamento)
    body.append('a_partir', from)
    body.append('action', action)
    if (action === 'confirm') steps.forEach((step) => body.append('passos[]', step))
    setBusy(true)
    await runAction({
      action: () => postForm('portal/monthly_schedule_import.php', body),
      notify,
      successMessage: action === 'confirm' ? 'Importação gravada.' : null,
      onSuccess: (result) => { if (action === 'preview') setPreview(result); else onDone() },
    })
    setBusy(false)
  }
  const change = (patch) => { setFiles((value) => ({ ...value, ...patch })); setPreview(null) }
  return (
    <Dialog description="A prévia mostra o que entra, o que muda, o que já está igual, os conflitos e o que não casou. Nada é gravado sem confirmar; o que a gestão marcou pela tela não é sobrescrito." onClose={onClose} size="max-w-3xl" title="Importar a planilha Base">
      <div className="space-y-4" data-month-import="">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Planilha Base (.xlsx)"><input accept=".xlsx" data-import-file="planilha" onChange={(event) => change({ planilha: event.target.files?.[0] ?? null })} type="file" /></Field>
          <Field label="Casamento nome → ID (.csv)"><input accept=".csv" data-import-file="casamento" onChange={(event) => change({ casamento: event.target.files?.[0] ?? null })} type="file" /></Field>
          <Field help={`Do próximo dia em diante; sugestão: o 1º dia útil de ${month}.`} label="Vale a partir de"><input data-import-from="" onChange={(event) => { setFrom(event.target.value); setPreview(null) }} type="date" value={from} /></Field>
        </div>
        <button className="btn-secondary" data-import-preview="" disabled={!ready || busy} onClick={() => send('preview')} type="button">Ver prévia</button>
        {preview && (
          <div className="space-y-3" data-import-result="">
            <table className="esc-summary">
              <thead><tr><th scope="col">Passo</th>{Object.keys(IMPORT_STATUS_LABELS).map((status) => <th key={status} scope="col">{IMPORT_STATUS_LABELS[status]}</th>)}<th scope="col">Gravar</th></tr></thead>
              <tbody>
                {IMPORT_STEPS.map((step) => (
                  <tr data-import-step={step} key={step}>
                    <td>{IMPORT_STEP_LABELS[step]}</td>
                    {Object.keys(IMPORT_STATUS_LABELS).map((status) => <td key={status}>{preview.contagens?.[step]?.[status] ?? 0}</td>)}
                    <td><input aria-label={`Gravar ${IMPORT_STEP_LABELS[step]}`} checked={steps.includes(step)} onChange={(event) => setSteps((value) => (event.target.checked ? [...value, step] : value.filter((item) => item !== step)))} type="checkbox" /></td>
                  </tr>
                ))}
              </tbody>
            </table>
            {preview.itens?.some((item) => item.situacao === 'conflito' || item.situacao === 'nao_casou') && (
              <InlineAlert title="Conflitos e itens sem casamento ficam de fora" tone="warning">
                {preview.itens.filter((item) => item.situacao === 'conflito' || item.situacao === 'nao_casou').map((item) => `${item.ref} (${IMPORT_STATUS_LABELS[item.situacao]})`).join(' · ')}
              </InlineAlert>
            )}
            <button className="btn-primary" data-import-confirm="" disabled={busy || steps.length === 0} onClick={() => send('confirm')} type="button">Confirmar e gravar</button>
          </div>
        )}
      </div>
    </Dialog>
  )
}

// [F10-5] Tela "Escala do mês": todos veem o publicado; prévia, publicar e
// regerar só Liderança e admin (o servidor confere de novo).
export function MonthlySchedulePage({ session, notify }) {
  const [month, setMonth] = useState(null)
  const [preview, setPreview] = useState(null)
  const [regenFrom, setRegenFrom] = useState('')
  const [confirmPublish, setConfirmPublish] = useState(false)
  const [importing, setImporting] = useState(false)
  const [busy, setBusy] = useState(false)
  const publishRef = useRef(null)
  const resource = useResource(`${ENDPOINT}${month ? `?mes=${month}` : ''}`)
  const data = resource.data

  if (resource.loading && !data) return <LoadingState />
  if (resource.error && !data) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />
  if (!data) return null

  const go = (key) => { setMonth(key); setPreview(null); setRegenFrom('') }
  const shown = preview ?? data
  const days = shown.days
  const people = [...shown.people].sort((a, b) => (TEAM_ORDER[a.team] ?? 9) - (TEAM_ORDER[b.team] ?? 9) || a.name.localeCompare(b.name))
  const canExport = hasPermission(session, 'relatorios.read') && data.current_revision > 0
  const canImport = hasPermission(session, 'escala_mensal.import')

  async function runPreview(from) {
    setBusy(true)
    await runAction({ action: () => post(ENDPOINT, { action: 'preview', mes: data.mes, ...(from ? { from } : {}) }), notify, successMessage: null, onSuccess: (result) => setPreview(result) })
    setBusy(false)
  }

  async function publish() {
    setBusy(true)
    await runAction({
      action: () => post(ENDPOINT, { action: 'publish', mes: data.mes, from_date: preview.from_date, expected_revision: preview.current_revision, input_hash: preview.input_hash }),
      notify,
      onSuccess: async () => { setPreview(null); setConfirmPublish(false); await resource.refresh() },
      onError: () => { setConfirmPublish(false); setPreview(null) },
    })
    setBusy(false)
  }

  const alerts = preview ? Object.values(preview.summary).filter((day) => statusAlert(day.status)).length : 0
  return (
    <div className="esc-page" data-month-page="">
      <div className="esc-toolbar">
        <div className="esc-toolbar-group">
          <button className="btn-secondary" data-month-prev="" onClick={() => go(data.prev)} type="button">Anterior</button>
          <button className="btn-secondary" onClick={() => go(data.today.slice(0, 7))} type="button">Hoje</button>
          <button className="btn-secondary" data-month-next="" onClick={() => go(data.next)} type="button">Próximo</button>
        </div>
        <div className="esc-toolbar-group">
          {data.can_publish && data.earliest_from && !preview && (
            <>
              <button className="btn-secondary" data-month-preview="" disabled={busy} onClick={() => runPreview(null)} type="button">{data.current_revision > 0 ? 'Prévia a partir de amanhã' : 'Gerar prévia'}</button>
              {data.current_revision > 0 && (
                <span className="flex items-center gap-2">
                  <input aria-label="Regerar a partir de" data-month-regen-date="" min={data.earliest_from} onChange={(event) => setRegenFrom(event.target.value)} type="date" value={regenFrom} />
                  <button className="btn-secondary" data-month-regen="" disabled={busy || !regenFrom} onClick={() => runPreview(regenFrom)} type="button">Regerar a partir…</button>
                </span>
              )}
            </>
          )}
          {canImport && <button className="btn-secondary" data-month-import-open="" onClick={() => setImporting(true)} type="button">Importar planilha Base</button>}
          {canExport && <a className="btn-secondary" data-month-export="" download href={apiUrl(`${ENDPOINT}?mes=${data.mes}&formato=xlsx`)}>Exportar Excel</a>}
        </div>
      </div>
      <div>
        <h2 className="section-title esc-title" data-month-title="">{data.label}</h2>
        <p className="text-sm text-muted" data-month-revision="">
          {data.current_revision > 0 ? `Publicada: revisão ${data.current_revision}${data.revisions?.[0] ? `, por ${data.revisions[0].by}` : ''}.` : 'Ainda não publicada.'}
          {data.divergences > 0 && ` ${data.divergences} célula(s) divergem da presença de hoje (ausência ou exceção depois de publicar).`}
        </p>
      </div>
      {preview && (
        <InlineAlert title={`Prévia a partir de ${shortDay(preview.from_date)} (nada gravado)`} tone={alerts > 0 ? 'warning' : 'info'}>
          <span data-month-preview-banner="">
            {preview.counts.linhas} linhas, {preview.counts.presenciais} presenciais, {preview.counts.sem_pa} sem PA, {preview.counts.trocas} trocas; {alerts} dia(s) com alerta.
            {preview.current_revision > 0 && ` ${preview.changed_cells} célula(s) mudam em relação ao publicado.`}
          </span>
          <span className="mt-2 flex flex-wrap gap-2">
            <button className="btn-primary" data-month-publish="" disabled={busy} onClick={() => setConfirmPublish(true)} ref={publishRef} type="button">{preview.current_revision > 0 ? 'Publicar regeração' : 'Publicar'}</button>
            <button className="btn-secondary" onClick={() => setPreview(null)} type="button">Descartar prévia</button>
          </span>
        </InlineAlert>
      )}
      <div aria-label="Legenda" className="esc-legend" role="list">
        {LEGEND.map((item) => <span className={`esc-legend-item ${toneClass(item.tone)}`} key={item.tone} role="listitem">{item.label}</span>)}
        <span className="text-xs text-muted">⇄ trocou de PA · ! diverge da presença de hoje</span>
      </div>
      {people.length === 0 && !preview ? (
        <EmptyState description={data.can_publish ? 'Gere a prévia e publique o mês.' : 'A escala deste mês ainda não foi publicada.'} title="Mês sem escala publicada" />
      ) : (
        <>
          {weeksOf(days).map((week) => <MonthWeek cells={shown.cells} holidays={data.holidays} key={week.start} people={people} today={data.today} week={week} />)}
          <MonthSummary days={days} summary={shown.summary} />
        </>
      )}
      {confirmPublish && preview && (
        <ConfirmActionDialog
          busy={busy}
          busyLabel="Publicando..."
          confirmClassName="btn-primary"
          confirmLabel="Publicar"
          description={`A escala passa a valer de ${shortDay(preview.from_date)} em diante, com ${preview.counts.linhas} linhas. ${alerts > 0 ? `${alerts} dia(s) com alerta: ${[...new Set(Object.values(preview.summary).flatMap((day) => day.status).filter((item) => item !== 'OK'))].map((item) => statusLabel([item])).join(', ')}.` : 'Nenhum dia com alerta.'} O passado não é reescrito.`}
          onCancel={() => setConfirmPublish(false)}
          onConfirm={publish}
          returnFocusRef={publishRef}
          title={preview.current_revision > 0 ? 'Publicar a regeração?' : 'Publicar a escala do mês?'}
        />
      )}
      {importing && <BaseImportDialog month={data.label} notify={notify} onClose={() => setImporting(false)} onDone={async () => { setImporting(false); await resource.refresh() }} />}
    </div>
  )
}
