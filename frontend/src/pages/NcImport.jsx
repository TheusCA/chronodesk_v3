import { useRef, useState } from 'react'
import { postForm } from '../lib/api'
import { ncImportCanConfirm, ncImportSummary } from '../lib/employeeNc'
import { InlineAlert } from '../components/ui/Primitives'

// [Lote NC revisto, D-NC-11 a D-NC-13] Importação de NCs com prévia, em
// Funcionários (só Liderança e admin, que veem o NC). A planilha (.xlsx ou
// .csv, com as colunas NC e Nome completo; o modelo do RH serve) vai na
// prévia e de novo no confirmar: o servidor refaz a prévia e grava só o que
// entra e o que muda. Nada é gravado sem confirmar.
function NcList({ title, items, render, tone = 'neutral' }) {
  if (!items?.length) return null
  return (
    <details className="rounded-lg border border-line bg-surface-2 px-3 py-2" data-nc-import-list={title}>
      <summary className="cursor-pointer text-sm font-semibold text-ink-2">{title} ({items.length})</summary>
      <ul className={`mt-2 space-y-1 text-sm ${tone === 'danger' ? 'text-danger' : 'text-ink-2'}`}>
        {items.map((item, index) => <li key={`${item.employee_id ?? item.nome}-${index}`}>{render(item)}</li>)}
      </ul>
    </details>
  )
}

export function NcImportPanel({ notify, onImported }) {
  const [file, setFile] = useState(null)
  const [preview, setPreview] = useState(null)
  const [busy, setBusy] = useState(false)
  const inputRef = useRef(null)

  async function send(action) {
    if (!file) return
    const body = new FormData()
    body.append('spreadsheet', file)
    body.append('action', action)
    setBusy(true)
    try {
      const result = await postForm('portal/employee_nc_import.php', body)
      if (action === 'preview') {
        setPreview(result)
        return
      }
      notify(`NCs importados: ${result.resumo.entra} incluído(s) e ${result.resumo.muda} alterado(s).`)
      setPreview(null)
      setFile(null)
      if (inputRef.current) inputRef.current.value = ''
      onImported?.()
    } catch (error) {
      notify(error?.message || 'Não foi possível ler a planilha.', 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="card space-y-4" data-nc-import="">
      <div>
        <h3 className="text-base font-semibold text-ink">Importar NCs</h3>
        <p className="text-sm text-muted">Planilha .xlsx ou .csv com as colunas NC e Nome completo (o modelo do RH serve). Casa pelo nome com os cadastros ativos; nada é gravado sem confirmar.</p>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <label className="label">Planilha
          <input accept=".xlsx,.csv" className="field mt-2" data-nc-import-file="" ref={inputRef} type="file" onChange={(event) => { setFile(event.target.files?.[0] ?? null); setPreview(null) }} />
        </label>
        <button className="btn-secondary" data-nc-import-preview="" disabled={!file || busy} onClick={() => send('preview')} type="button">Ver prévia</button>
        <button className="btn-primary" data-nc-import-confirm="" disabled={!ncImportCanConfirm(preview) || busy} onClick={() => send('confirm')} type="button">Confirmar importação</button>
      </div>
      {preview?.resumo && (
        <div className="space-y-3" data-nc-import-summary="">
          <div className="flex flex-wrap gap-2">
            {ncImportSummary(preview.resumo).map(([label, count]) => <span className="status-badge status-neutral" data-nc-count={label} key={label}>{label}: {count}</span>)}
          </div>
          {!ncImportCanConfirm(preview) && <InlineAlert tone="info" title="Nada a gravar">Nenhum NC entra nem muda com esta planilha.</InlineAlert>}
          <NcList items={preview.entra} render={(item) => `${item.nome}: ${item.nc}`} title="Entram" />
          <NcList items={preview.muda} render={(item) => `${item.nome}: ${item.nc_atual} → ${item.nc}`} title="Mudam" />
          <NcList items={preview.nao_casou} render={(item) => `${item.nome} (${item.motivo})`} title="Não casaram" tone="danger" />
          <NcList items={preview.nc_repetido} render={(item) => `${item.nome}: ${item.nc} (${item.motivo})`} title="NC repetido" tone="danger" />
          <NcList items={preview.nc_invalido} render={(item) => `Linha ${item.linha}: ${item.nome || 'sem nome'} (${item.motivo})`} title="NC inválido" tone="danger" />
        </div>
      )}
    </section>
  )
}
