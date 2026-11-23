import { useRef, useState } from 'react'
import { Drawer } from '../../components/ui/Drawer'
import { Field } from '../../components/ui/Field'
import { postForm } from '../../lib/api'
import { CATALOG_GROUPS, catalogCanConfirm, catalogCounts, catalogSummaryRows } from './chgImportModel'

// [Ajustes do CHG, D-CHG-39 e D-CHG-40] Catálogo de carteiras (só admin,
// can_import do servidor): as contagens do catálogo e a importação da planilha
// base (.xlsx ou .csv com as colunas cod e carteira; as demais são ignoradas e
// nunca gravadas). A prévia mostra as novas, as renomeadas, as reativadas, as
// que saem (viram inativas, nunca apagadas) e as recusadas; nada é gravado sem
// confirmar. Os cards já gravados não mudam.

const ENDPOINT = 'portal/chg_catalog_import.php'

function CatalogList({ group, items }) {
  if (!items?.length) return null
  const render = (item) => (group.key === 'recusadas'
    ? `Linha ${item.linha}: ${item.codigo || 'sem código'} ${item.nome || ''} (${item.motivo})`
    : group.key === 'renomeadas' ? `${item.codigo}: ${item.nome_atual} → ${item.nome}` : `${item.codigo}: ${item.nome}`)
  return (
    <details className="chg-multi" data-chg-catalog-list={group.key}>
      <summary className="chg-multi-head">{group.label} ({items.length})</summary>
      <ul className="chg-entries">{items.map((item, i) => <li key={`${item.codigo}-${i}`}>{render(item)}</li>)}</ul>
    </details>
  )
}

export function ChgCatalogPanel({ carteiras, notify, onClose, onDone }) {
  const [file, setFile] = useState(null)
  const [preview, setPreview] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const inputRef = useRef(null)
  const counts = catalogCounts(carteiras)

  async function send(action) {
    if (!file) return
    const body = new FormData()
    body.append('spreadsheet', file)
    body.append('action', action)
    setBusy(true)
    setError(null)
    try {
      const result = await postForm(ENDPOINT, body)
      if (action === 'preview') {
        setPreview(result)
        return
      }
      notify(`Catálogo importado: ${result.mudancas} mudança(s).`)
      onDone()
      onClose()
    } catch (sendError) {
      setError(sendError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Drawer
      description="Prévia antes de gravar. As carteiras fora da planilha ficam inativas (nunca apagadas); os cards já gravados não mudam."
      footer={(
        <>
          <button className="btn-secondary chg-btn" onClick={onClose} type="button">Fechar</button>
          {preview
            ? <button className="btn-primary chg-btn" data-chg-catalog-confirm="" disabled={busy || !catalogCanConfirm(preview)} onClick={() => send('confirm')} type="button">{busy ? 'Importando...' : 'Confirmar importação'}</button>
            : <button className="btn-primary chg-btn" data-chg-catalog-preview="" disabled={busy || !file} onClick={() => send('preview')} type="button">{busy ? 'Lendo...' : 'Ver prévia'}</button>}
        </>
      )}
      onClose={onClose}
      title="Catálogo de carteiras"
    >
      <div className="chg-form" data-chg-catalog="">
        <p className="text-sm text-ink-2" data-chg-catalog-counts="">{counts.ativas} carteira(s) ativa(s) e {counts.inativas} inativa(s) no catálogo.</p>
        {error && <p className="field-error" role="alert">{error}</p>}
        <Field help="A planilha base de carteiras (.xlsx ou .csv), com as colunas cod e carteira. As outras colunas são ignoradas." label="Planilha de carteiras">
          <input accept=".xlsx,.csv" data-chg-catalog-file="" onChange={(event) => { setFile(event.target.files?.[0] ?? null); setPreview(null); setError(null) }} ref={inputRef} type="file" />
        </Field>
        {preview && (
          <div className="chg-panel" data-chg-catalog-summary="">
            <table className="w-full text-sm">
              <tbody>{catalogSummaryRows(preview).map(([label, value]) => <tr key={label}><td className="py-1">{label}</td><td className="py-1 text-right"><strong>{value}</strong></td></tr>)}</tbody>
            </table>
            {!catalogCanConfirm(preview) && <p className="chg-hint">{preview.pode_confirmar ? 'Nada muda com esta planilha.' : 'Nenhuma carteira válida na planilha: nada a importar.'}</p>}
            {CATALOG_GROUPS.filter((g) => g.key !== 'sem_mudanca').map((group) => <CatalogList group={group} items={preview[group.key]} key={group.key} />)}
          </div>
        )}
      </div>
    </Drawer>
  )
}
