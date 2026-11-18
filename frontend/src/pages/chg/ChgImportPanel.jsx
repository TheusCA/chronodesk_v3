import { useState } from 'react'
import { Drawer } from '../../components/ui/Drawer'
import { Field } from '../../components/ui/Field'
import { post } from '../../lib/api'
import { readJsonFile, summaryRows } from './chgImportModel'

// [9.17.7] Importar do Controle v5 (só admin, can_import do servidor): o JSON
// de dados v2 e o do catálogo. Prévia só com contagens; depois, confirmar. O
// arquivo é lido no navegador e mandado como JSON (até 2 MB, conferido de
// novo no servidor).

const ENDPOINT = 'portal/chg_import.php'

function ImportSummary({ result }) {
  return (
    <div className="chg-panel" data-chg-import-summary="">
      <table className="w-full text-sm">
        <tbody>
          {summaryRows(result).map(([label, value]) => <tr key={label}><td className="py-1">{label}</td><td className="py-1 text-right"><strong>{value}</strong></td></tr>)}
        </tbody>
      </table>
    </div>
  )
}

export function ChgImportPanel({ notify, onClose, onDone }) {
  const [files, setFiles] = useState({ dados: null, catalogo: null })
  const [preview, setPreview] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function send(action) {
    setBusy(true)
    setError(null)
    try {
      const body = { action, dados: await readJsonFile(files.dados), catalogo: await readJsonFile(files.catalogo) }
      const result = await post(ENDPOINT, body)
      if (action === 'preview') setPreview(result)
      else {
        notify(result.mensagem || 'Importação concluída.')
        onDone()
        onClose()
      }
    } catch (sendError) {
      setError(sendError.message)
    } finally {
      setBusy(false)
    }
  }

  const choose = (key) => (event) => {
    setFiles((current) => ({ ...current, [key]: event.target.files?.[0] ?? null }))
    setPreview(null)
  }

  return (
    <Drawer
      description="Prévia só com contagens; nada é gravado antes de confirmar. Cards em 'deleted' nunca são apagados aqui."
      footer={(
        <>
          <button className="btn-secondary chg-btn" onClick={onClose} type="button">Cancelar</button>
          {preview
            ? <button className="btn-primary chg-btn" data-chg-import-confirm="" disabled={busy} onClick={() => send('confirm')} type="button">{busy ? 'Importando...' : 'Confirmar importação'}</button>
            : <button className="btn-primary chg-btn" data-chg-import-preview="" disabled={busy || (!files.dados && !files.catalogo)} onClick={() => send('preview')} type="button">{busy ? 'Lendo...' : 'Ver prévia'}</button>}
        </>
      )}
      onClose={onClose}
      title="Importar do Controle v5"
    >
      <div className="chg-form" data-chg-import="">
        {error && <p className="field-error" role="alert">{error}</p>}
        <Field help="O JSON de dados (versão 2), com items e deleted." label="Dados do Controle v5"><input accept=".json,application/json" onChange={choose('dados')} type="file" /></Field>
        <Field help="O JSON do catálogo, com carteiras e locais." label="Catálogo"><input accept=".json,application/json" onChange={choose('catalogo')} type="file" /></Field>
        {preview && <ImportSummary result={preview} />}
      </div>
    </Drawer>
  )
}
