import { useState } from 'react'
import { ConfirmActionDialog } from '../components/ui/ConfirmActionDialog'
import { ConfirmDiscardDialog } from '../components/ui/ConfirmDiscardDialog'
import { Field } from '../components/ui/Field'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { useResource } from '../hooks/useResource'
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard'
import { post } from '../lib/api'
import { runAction } from '../lib/actionRunner'
import { formatDate } from '../lib/format'

const ENDPOINT = 'portal/holidays.php'

// [D-ESC-16] Feriados (Configurações): Liderança e admin cadastram, alteram e
// removem; data única, nome. No motor, o feriado é dia sem expediente para
// todos (D-ESC-17). O servidor confere a permissão e a versão (409).
export function HolidaysPanel({ notify }) {
  const [year, setYear] = useState(() => new Date().getFullYear())
  const resource = useResource(`${ENDPOINT}?year=${year}`)
  const empty = { id: null, row_version: null, date: '', name: '' }
  const [form, setForm] = useState(empty)
  const [busy, setBusy] = useState(false)
  const [removing, setRemoving] = useState(null)
  const dirty = form.date !== '' || form.name !== ''
  const guard = useUnsavedChangesGuard(dirty)
  const data = resource.data

  async function save() {
    setBusy(true)
    await runAction({
      action: () => post(ENDPOINT, { action: 'save', id: form.id, row_version: form.row_version, date: form.date, name: form.name }),
      notify,
      refresh: resource.refresh,
      onSuccess: () => setForm(empty),
      onError: () => resource.refresh(),
    })
    setBusy(false)
  }

  async function remove() {
    setBusy(true)
    await runAction({ action: () => post(ENDPOINT, { action: 'delete', id: removing.id, row_version: removing.row_version }), notify, refresh: resource.refresh, onError: () => resource.refresh() })
    setRemoving(null)
    setBusy(false)
  }

  return (
    <section className="card space-y-4 md:col-span-2" data-holidays="">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-sm text-muted">Escalas</p>
          <h2 className="mt-1 text-xl font-semibold text-ink">Feriados</h2>
          <p className="mt-1 text-sm text-muted">Dia sem expediente para todos (não é falta nem entra no “pelo menos 1 disponível”); o ciclo e o rodízio continuam contando o dia.</p>
        </div>
        <Field label="Ano">
          <input data-holidays-year="" max="2100" min="2000" onChange={(event) => guard.requestLeave(() => { setYear(Number(event.target.value) || year); setForm(empty) })} type="number" value={year} />
        </Field>
      </div>
      {resource.loading && !data ? <LoadingState shape="table" /> : resource.error ? <ErrorState message={resource.error.message} onRetry={resource.refresh} /> : (
        data.items.length === 0 ? <EmptyState description="Cadastre os feriados do ano." title="Nenhum feriado" /> : (
          <ul className="divide-y divide-line rounded-lg border border-line" data-holidays-list="">
            {data.items.map((item) => (
              <li className="flex flex-wrap items-center justify-between gap-2 px-3 py-2" data-holiday={item.id} key={item.id}>
                <span><strong className="text-ink">{formatDate(item.date)}</strong> · {item.name}</span>
                {data.can_manage && (
                  <span className="flex gap-2">
                    <button className="table-action" data-holiday-edit={item.id} onClick={() => guard.requestLeave(() => setForm({ id: item.id, row_version: item.row_version, date: item.date, name: item.name }))} type="button">Editar</button>
                    <button className="table-action" data-holiday-remove={item.id} onClick={() => setRemoving(item)} type="button">Remover</button>
                  </span>
                )}
              </li>
            ))}
          </ul>
        )
      )}
      {data?.can_manage && (
        <div className="grid gap-3 rounded-lg border border-line bg-surface-2 p-3 sm:grid-cols-[12rem_1fr_auto]" data-holiday-form="">
          <Field label="Data">
            <input data-holiday-date="" onChange={(event) => setForm((value) => ({ ...value, date: event.target.value }))} type="date" value={form.date} />
          </Field>
          <Field label="Nome">
            <input data-holiday-name="" maxLength="80" onChange={(event) => setForm((value) => ({ ...value, name: event.target.value }))} value={form.name} />
          </Field>
          <div className="flex items-end gap-2">
            <button className="btn-primary" data-holiday-save="" disabled={busy || !form.date || !form.name.trim()} onClick={save} type="button">{form.id ? 'Salvar' : 'Cadastrar'}</button>
            {dirty && <button className="btn-secondary" onClick={() => setForm(empty)} type="button">Cancelar</button>}
          </div>
        </div>
      )}
      {removing && <ConfirmActionDialog busy={busy} busyLabel="Removendo..." confirmLabel="Remover" description={`${formatDate(removing.date)} volta a ser dia útil para todos.`} onCancel={() => setRemoving(null)} onConfirm={remove} title="Remover o feriado?" />}
      <ConfirmDiscardDialog {...guard.dialogProps} />
    </section>
  )
}
