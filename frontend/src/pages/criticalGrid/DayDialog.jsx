import { useRef, useState } from 'react'
import { ConfirmActionDialog } from '../../components/ui/ConfirmActionDialog'
import { ConfirmDiscardDialog } from '../../components/ui/ConfirmDiscardDialog'
import { Dialog } from '../../components/ui/Dialog'
import { Field } from '../../components/ui/Field'
import { Segmented } from '../../components/ui/Segmented'
import { InlineAlert } from '../../components/ui/Primitives'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { post } from '../../lib/api'
import { runAction } from '../../lib/actionRunner'
import { SOURCE_LABELS, changeLabel, dayLabel, toneClass, versionsFor } from '../../lib/criticalGrid'

const ENDPOINT = 'portal/critical_grid.php'
const UNDO_LABELS = { troca: 'Desfazer a troca', ausencia: 'Desfazer a ausência', excecao: 'Desfazer a exceção' }

function addDays(date, days) {
  const value = new Date(`${date}T12:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

function shortDate(date) {
  return `${date.slice(8, 10)}/${date.slice(5, 7)}`
}

// Leitura do dia: papel, jornada, origem e quem alterou por último (D-ESC-5: a
// ausência já vem do servidor no rótulo que cada perfil pode ver).
export function DayReadout({ cell, personName, date }) {
  return (
    <div className="esc-readonly space-y-3" data-esc-readout="">
      <div className={`rounded-lg border px-3 py-2 text-center font-semibold ${toneClass(cell.tone)}`}>{cell.label}</div>
      <dl>
        <dt>Técnico</dt><dd>{personName}</dd>
        <dt>Dia</dt><dd>{dayLabel(date)}</dd>
        <dt>Jornada</dt><dd>{cell.hours || '—'}</dd>
        <dt>Origem</dt><dd data-esc-source="">{SOURCE_LABELS[cell.source] || cell.source}</dd>
        {cell.detail && (<><dt>Detalhe</dt><dd>{cell.detail}</dd></>)}
        <dt>Alterado por</dt><dd data-esc-author="">{changeLabel(cell.last_change)}</dd>
      </dl>
    </div>
  )
}

// [D-ESC-7 a D-ESC-12] Diálogo do dia (Liderança e admin): trocar o papel,
// marcar ausência, exceção ou desfazer, sempre com a prévia do servidor antes
// de salvar; a versão de cada dia vai junto (409 se mudou).
export function DayDialog({ grid, selection, notify, onClose, onSaved, returnFocusRef }) {
  const person = grid.people.find((item) => item.id === selection.employeeId)
  const cell = grid.cells[`${selection.employeeId}:${selection.from}`]
  const single = selection.from === selection.to
  const undoTargets = single ? Object.keys(cell.refs || {}) : []
  const activeRoles = grid.roles.filter((role) => role.active)
  const initial = { mode: 'role', role_id: '', until: selection.to, category: 'ferias', exception_type: 'onsite', target: undoTargets[0] || '' }
  const [form, setForm] = useState(initial)
  const [preview, setPreview] = useState(null)
  const [busy, setBusy] = useState(false)
  const [confirmUndo, setConfirmUndo] = useState(false)
  const headingRef = useRef(null)
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)
  const guard = useUnsavedChangesGuard(dirty)
  const update = (patch) => { setForm((value) => ({ ...value, ...patch })); setPreview(null) }

  const change = () => {
    if (form.mode === 'role') return { type: 'role', role_id: Number(form.role_id) }
    if (form.mode === 'absence') return { type: 'absence', category: form.category }
    if (form.mode === 'exception') return { type: 'exception', exception_type: form.exception_type }
    return { type: 'undo', target: form.target, ref_id: cell.refs[form.target]?.id }
  }
  const range = form.mode === 'undo' ? { from: selection.from, to: selection.from } : { from: selection.from, to: form.until }
  const body = () => ({ employee_id: selection.employeeId, ...range, change: change() })
  const incomplete = (form.mode === 'role' && !form.role_id) || (form.mode === 'undo' && !form.target) || !range.to || range.to < range.from

  async function runPreview() {
    setBusy(true)
    await runAction({ action: () => post(ENDPOINT, { action: 'preview', ...body() }), notify, successMessage: null, onSuccess: (result) => setPreview(result) })
    setBusy(false)
  }

  async function save() {
    setBusy(true)
    const versions = versionsFor(grid.cells, selection.employeeId, range.from, range.to)
    await runAction({
      action: () => post(ENDPOINT, { action: 'apply', ...body(), versions }),
      notify,
      onSuccess: () => onSaved(),
      onError: () => onSaved(),
    })
    setBusy(false)
    setConfirmUndo(false)
  }

  const modes = [
    { value: 'role', label: 'Trocar o papel' },
    { value: 'absence', label: 'Ausência' },
    { value: 'exception', label: 'Exceção' },
    ...(undoTargets.length > 0 ? [{ value: 'undo', label: 'Desfazer' }] : []),
  ]
  const title = single ? `${person?.name || ''} · ${dayLabel(selection.from)}` : `${person?.name || ''} · ${dayLabel(selection.from)} a ${dayLabel(selection.to)}`

  return (
    <Dialog
      description="A prévia do servidor mostra o efeito antes de salvar. Ausência e exceção seguem as regras de sempre."
      initialFocusRef={headingRef}
      onClose={() => guard.requestLeave(onClose)}
      returnFocusRef={returnFocusRef}
      size="max-w-2xl"
      title={title}
    >
      <div className="space-y-4" data-esc-dialog="" ref={headingRef} tabIndex={-1}>
        {single && <DayReadout cell={cell} date={selection.from} personName={person?.name || ''} />}
        <Segmented label="O que fazer" onChange={(mode) => update({ mode })} options={modes} value={form.mode} />
        <div className="grid gap-3 sm:grid-cols-2">
          {form.mode === 'role' && (
            <Field label="Papel">
              <select data-esc-role="" onChange={(event) => update({ role_id: event.target.value })} value={form.role_id}>
                <option value="">Selecione</option>
                {activeRoles.map((role) => <option key={role.id} value={role.id}>{role.label}</option>)}
              </select>
            </Field>
          )}
          {form.mode === 'absence' && (
            <Field help="Atestado entra como Ausência justificada (sem dado de saúde)." label="Tipo">
              <select data-esc-category="" onChange={(event) => update({ category: event.target.value })} value={form.category}>
                {grid.absence_choices.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </Field>
          )}
          {form.mode === 'exception' && (
            <Field label="Exceção do dia">
              <select data-esc-exception="" onChange={(event) => update({ exception_type: event.target.value })} value={form.exception_type}>
                {grid.exception_choices.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </Field>
          )}
          {form.mode !== 'undo' && (
            <Field help={form.until === selection.from ? 'Só este dia.' : `De ${shortDate(selection.from)} até ${shortDate(form.until)}.`} label="Até">
              <input data-esc-until="" max={addDays(selection.from, 30)} min={selection.from} onChange={(event) => update({ until: event.target.value })} type="date" value={form.until} />
            </Field>
          )}
          {form.mode === 'undo' && (
            <Field label="Desfazer">
              <select data-esc-undo="" onChange={(event) => update({ target: event.target.value })} value={form.target}>
                {undoTargets.map((target) => {
                  const ref = cell.refs[target]
                  const span = ref.starts_on && ref.ends_on && ref.starts_on !== ref.ends_on ? ` (inteira: ${shortDate(ref.starts_on)} a ${shortDate(ref.ends_on)})` : ''
                  return <option key={target} value={target}>{UNDO_LABELS[target]}{span}</option>
                })}
              </select>
            </Field>
          )}
        </div>
        {preview && (
          <div className="space-y-2 rounded-lg border border-line bg-surface-2 p-3" data-esc-preview="">
            <p className="text-xs font-semibold text-muted">Efeito (calculado pelo servidor)</p>
            <ul className="esc-effects">{preview.changes.map((item) => <li key={item.date}>{item.text}</li>)}</ul>
            {preview.warnings.length > 0 && (
              <InlineAlert title="Sem ninguém disponível" tone="warning">
                {preview.warnings.map((item) => item.text).join(' ')} Dá para salvar mesmo assim.
              </InlineAlert>
            )}
          </div>
        )}
        <div className="flex flex-wrap gap-2">
          <button className="btn-secondary" data-esc-preview-run="" disabled={busy || incomplete} onClick={runPreview} type="button">{busy && !preview ? 'Calculando...' : 'Ver efeito'}</button>
          {form.mode === 'undo'
            ? <button className="btn-danger" data-esc-undo-run="" disabled={busy || !preview} onClick={() => setConfirmUndo(true)} type="button">Desfazer</button>
            : <button className="btn-primary" data-esc-save="" disabled={busy || !preview} onClick={save} type="button">Salvar</button>}
          <button className="btn-secondary" onClick={() => guard.requestLeave(onClose)} type="button">Cancelar</button>
        </div>
        {!preview && <p className="text-xs text-muted">Salvar fica disponível depois do efeito calculado.</p>}
      </div>
      {confirmUndo && (
        <ConfirmActionDialog busy={busy} busyLabel="Desfazendo..." confirmLabel="Desfazer" description={`${UNDO_LABELS[form.target]}: o efeito mostrado acima vale para todos os dias do registro.`} onCancel={() => setConfirmUndo(false)} onConfirm={save} title="Desfazer?" />
      )}
      <ConfirmDiscardDialog {...guard.dialogProps} />
    </Dialog>
  )
}
