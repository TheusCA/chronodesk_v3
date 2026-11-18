import { useMemo, useRef, useState } from 'react'
import { ConfirmActionDialog } from '../components/ui/ConfirmActionDialog'
import { Dialog } from '../components/ui/Dialog'
import { Segmented } from '../components/ui/Segmented'
import { ErrorState, LoadingState } from '../components/ui/States'
import { useResource } from '../hooks/useResource'
import { api, apiUrl, post } from '../lib/api'
import {
  CHG_LABELS, EMPTY_FILTERS, NO_TECH, STAGES, TIPO_LABELS, filterCards, kpis, loadPrefs, savePrefs, sortByWindow,
  techFilterOptions, uniqueSorted,
} from '../lib/chg'
import { ChgCard } from './chg/ChgCard'
import { ChgForm } from './chg/ChgForm'
import { ChgImportPanel } from './chg/ChgImportPanel'
import { ChgDashboard, ChgList } from './chg/ChgViews'
import './chg/chg.css'

// [9.17] Controle de CHG (substitui o Controle de CHG v5.2). Todo perfil vê;
// quem tem chg.manage (perfil ou grupo Tools, decidido pelo servidor em
// can_manage) edita. Atualiza a cada 30 s e ao voltar o foco (o "agora" do
// servidor muda a etapa).

const ENDPOINT = 'portal/chg.php'
const REFRESH_MS = 30_000

// [D-CHG-32] Preferências na sessão da aba (como o tema, D-R1-1): a regra de
// AppSec do frontend não deixa nada no armazenamento persistente do navegador.
function storage() {
  try {
    return window.sessionStorage
  } catch {
    return null
  }
}

/** Cópia para a área de transferência: HTML + texto; sem permissão do navegador, devolve false. */
async function copyRich(html, text) {
  try {
    if (navigator.clipboard && window.ClipboardItem) {
      await navigator.clipboard.write([new window.ClipboardItem({ 'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([text], { type: 'text/plain' }) })])
      return true
    }
  } catch {
    // Cai na prévia selecionável.
  }
  return false
}

async function copyPlain(text) {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    return false
  }
}

function PreviewTable({ rows }) {
  return (
    <div className="chg-preview" data-chg-preview="" tabIndex={0}>
      <table>
        <tbody>
          <tr className="chg-pv-section"><td colSpan={2}>Dados</td></tr>
          {rows.map((row, i) => (row.section
            ? <tr className={row.rollback ? 'chg-pv-rollback' : 'chg-pv-section'} key={i}><td colSpan={2}>{row.k}</td></tr>
            : <tr key={i}><td className={row.bold ? 'chg-pv-strong' : undefined}>{row.k}</td><td className={row.bold || row.color ? 'chg-pv-strong' : undefined}>{row.v}</td></tr>))}
        </tbody>
      </table>
    </div>
  )
}

export function ChgPage({ notify }) {
  const resource = useResource(ENDPOINT, { intervalMs: REFRESH_MS })
  const { data, loading, error, refresh, setData } = resource
  const [filters, setFilters] = useState(() => ({ ...EMPTY_FILTERS, ...loadPrefs(storage()) }))
  const [view, setView] = useState('board')
  const [formState, setFormState] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [confirmBusy, setConfirmBusy] = useState(false)
  const [preview, setPreview] = useState(null)
  const [dropStage, setDropStage] = useState(null)
  const [importOpen, setImportOpen] = useState(false)
  const dragId = useRef(null)

  const cards = useMemo(() => data?.cards || [], [data])
  const canManage = data?.can_manage === true
  const technicians = data?.tecnicos || []
  const list = useMemo(() => filterCards(cards, filters), [cards, filters])
  const indicators = useMemo(() => kpis(list, data?.now || ''), [list, data?.now])

  function updateFilters(patch) {
    const next = { ...filters, ...patch }
    setFilters(next)
    if ('tecnico' in patch || 'fechadas' in patch) savePrefs(storage(), next)
  }

  function replaceCard(card) {
    setData((current) => (current ? { ...current, cards: card.archived ? current.cards.filter((c) => c.id !== card.id) : current.cards.map((c) => (c.id === card.id ? card : c)) } : current))
  }

  /** Ação do card no servidor; 409 de versão recarrega o quadro. Devolve o card novo ou null. */
  async function act(card, action, extra = {}) {
    try {
      const result = await post(ENDPOINT, { action, id: card.id, row_version: card.row_version, ...extra })
      replaceCard(result.card)
      return result.card
    } catch (actionError) {
      notify(actionError.message, 'error')
      if (actionError.status === 409 || actionError.status === 400) refresh()
      return null
    }
  }

  async function saveForm(payload) {
    const editing = formState?.mode === 'edit' ? formState.card : null
    try {
      const result = await post(ENDPOINT, editing ? { action: 'update', id: editing.id, row_version: editing.row_version, ...payload } : { action: 'create', ...payload })
      notify(result.mensagem || 'Atualização salva.')
      await refresh()
      return true
    } catch (saveError) {
      notify(saveError.message, 'error')
      if (saveError.status === 409) refresh()
      return false
    }
  }

  async function openDuplicate(card) {
    try {
      const result = await api(`${ENDPOINT}?duplicar=${card.id}`)
      setFormState({ mode: 'duplicate', card: result.base })
    } catch (duplicateError) {
      notify(duplicateError.message, 'error')
    }
  }

  async function copyValue(value, label) {
    const ok = await copyPlain(value)
    notify(ok ? `${label.charAt(0).toUpperCase()}${label.slice(1)} copiado.` : 'Não foi possível copiar. Selecione o texto e use Ctrl+C.', ok ? 'success' : 'error')
  }

  // A tabela (HTML com as cores do v5.2 e o texto) vem montada do servidor.
  async function copyTable(card) {
    try {
      const table = await api(`${ENDPOINT}?tabela=${card.id}`)
      if (await copyRich(table.html, table.texto)) {
        notify('Tabela copiada. Cole no e-mail, Teams ou Excel.')
        return
      }
    } catch (copyError) {
      notify(copyError.message, 'error')
      return
    }
    setPreview(card)
  }

  async function runConfirm() {
    if (!confirm) return
    setConfirmBusy(true)
    await confirm.run()
    setConfirmBusy(false)
    setConfirm(null)
  }

  const drag = canManage ? {
    start: (event, card) => {
      if (event.target.closest('input,select,button,textarea,a,summary')) {
        event.preventDefault()
        return
      }
      dragId.current = card.id
      event.dataTransfer.effectAllowed = 'move'
    },
  } : null

  function dropOn(stage) {
    const card = cards.find((c) => c.id === dragId.current)
    dragId.current = null
    setDropStage(null)
    if (card && card.view.stage !== stage) act(card, 'move', { stage })
  }

  if (loading && !data) return <LoadingState label="Carregando o Controle de CHG..." />
  if (error && !data) return <ErrorState message={error.message} onRetry={refresh} />

  const stages = filters.fechadas ? STAGES : STAGES.filter((s) => !['done', 'cancel'].includes(s.key))
  const exportIds = [...list].sort(sortByWindow).map((c) => c.id).join(',')
  const solicitantes = uniqueSorted(cards.map((c) => c.solicitante))
  const locais = uniqueSorted([...(data?.catalogos?.locais || []).map((l) => l.nome), ...cards.flatMap((c) => c.locais)])
  const cardProps = {
    canManage,
    technicians,
    onAct: act,
    onCopy: copyValue,
    onCopyTable: copyTable,
    onConfirm: setConfirm,
    onEdit: (card) => setFormState({ mode: 'edit', card }),
    onDuplicate: openDuplicate,
    onInvalid: (message) => notify(message, 'error'),
  }

  return (
    <div className="chg-page" data-chg-page="" data-chg-can-manage={canManage ? '1' : '0'}>
      <div className="chg-toolbar">
        <Segmented label="Visualização" onChange={setView} options={[{ value: 'board', label: 'Quadro' }, { value: 'list', label: 'Lista' }, { value: 'dash', label: 'Dashboard' }]} value={view} />
        <div className="chg-toolbar-actions">
          <a className="btn-secondary chg-btn" data-chg-export="" download href={apiUrl(`portal/chg_export.php?ids=${exportIds}`)}>Exportar Excel</a>
          {data?.can_import === true && <button className="btn-secondary chg-btn" data-chg-import-open="" onClick={() => setImportOpen(true)} type="button">Importar do Controle v5</button>}
          {canManage && <button className="btn-primary chg-btn" data-chg-new="" onClick={() => setFormState({ mode: 'new', card: null })} type="button">Nova atualização</button>}
        </div>
      </div>
      {!canManage && <p className="text-sm text-muted" data-chg-readonly="">Somente leitura: alterar o Controle de CHG é da Liderança, do admin e do grupo Tools.</p>}

      <section aria-label="Indicadores" className="chg-kpis">
        {indicators.map((kpi) => (
          <div className={`chg-kpi chg-tone-${kpi.tone}`} data-chg-kpi={kpi.key} key={kpi.key}>
            <div className="chg-kpi-label">{kpi.label}</div>
            <div className="chg-kpi-value">{kpi.value}</div>
            <div className="chg-kpi-detail">{kpi.detail}</div>
          </div>
        ))}
      </section>

      <div className="chg-filters" role="search">
        <label className="field-group"><span className="label">Buscar</span><input className="field" onChange={(e) => updateFilters({ q: e.target.value })} placeholder="Carteira, versões, CHG, técnico…" type="search" value={filters.q} /></label>
        <label className="field-group"><span className="label">Solicitante</span><select className="field" onChange={(e) => updateFilters({ solicitante: e.target.value })} value={filters.solicitante}><option value="">Todos</option>{solicitantes.map((s) => <option key={s} value={s}>{s}</option>)}</select></label>
        <label className="field-group"><span className="label">Técnico</span><select className="field" data-chg-filter-tecnico="" onChange={(e) => updateFilters({ tecnico: e.target.value })} value={filters.tecnico}><option value="">Todos</option><option value={NO_TECH}>Sem técnico</option>{techFilterOptions(cards, technicians).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></label>
        <label className="field-group"><span className="label">Local</span><select className="field" onChange={(e) => updateFilters({ local: e.target.value })} value={filters.local}><option value="">Todos</option>{locais.map((l) => <option key={l} value={l}>{l}</option>)}</select></label>
        <label className="field-group"><span className="label">Tipo</span><select className="field" onChange={(e) => updateFilters({ tipo: e.target.value })} value={filters.tipo}><option value="">Todos</option>{Object.entries(TIPO_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
        <label className="field-group"><span className="label">CHG</span><select className="field" onChange={(e) => updateFilters({ chg: e.target.value })} value={filters.chg}><option value="">Todas</option><option value="sem">Sem CHG</option>{['pendente', 'aprovada', 'cancelada'].map((v) => <option key={v} value={v}>{CHG_LABELS[v]}</option>)}</select></label>
        <label className="field-group"><span className="label">Janela de</span><input className="field" onChange={(e) => updateFilters({ de: e.target.value })} type="date" value={filters.de} /></label>
        <label className="field-group"><span className="label">até</span><input className="field" onChange={(e) => updateFilters({ ate: e.target.value })} type="date" value={filters.ate} /></label>
        <label className="chg-check"><input checked={filters.fechadas} data-chg-fechadas="" onChange={(e) => updateFilters({ fechadas: e.target.checked })} type="checkbox" />Mostrar concluídas e canceladas</label>
        <button className="btn-secondary chg-btn" onClick={() => { const next = { ...EMPTY_FILTERS, fechadas: filters.fechadas }; setFilters(next); savePrefs(storage(), next) }} type="button">Limpar filtros</button>
      </div>
      <p className="text-sm text-muted" data-chg-count="">{list.length} de {cards.length}</p>

      {view === 'board' && (
        <section aria-label="Quadro" className={`chg-board chg-board-${stages.length}`} data-chg-board="">
          {stages.map((stage) => {
            const stageCards = list.filter((c) => c.view.stage === stage.key).sort(sortByWindow)
            return (
              <section
                aria-label={stage.title}
                className={`chg-col chg-tone-${stage.key}${dropStage === stage.key ? ' chg-col-drop' : ''}`}
                data-chg-col={stage.key}
                key={stage.key}
                onDragLeave={() => setDropStage(null)}
                onDragOver={canManage ? (event) => { if (dragId.current) { event.preventDefault(); setDropStage(stage.key) } } : undefined}
                onDrop={canManage ? (event) => { event.preventDefault(); dropOn(stage.key) } : undefined}
              >
                <div className="chg-col-head">
                  <h2 className="chg-col-title">{stage.title}<span className="chg-col-count">{stageCards.length}</span></h2>
                  <p className="chg-col-hint">{stage.hint}</p>
                </div>
                {stageCards.length ? stageCards.map((card) => <ChgCard card={card} drag={drag} key={card.id} {...cardProps} />) : <p className="chg-empty">{stage.empty}</p>}
              </section>
            )
          })}
        </section>
      )}
      {view === 'list' && <ChgList cards={list} />}
      {view === 'dash' && <ChgDashboard cards={list} onOpen={(card) => (canManage ? setFormState({ mode: 'edit', card }) : (setView('board'), updateFilters({ tecnico: NO_TECH })))} withDone={filters.fechadas} />}

      {formState && (
        <ChgForm
          card={formState.card}
          catalog={data?.catalogos}
          key={`${formState.mode}-${formState.card?.id ?? 'novo'}`}
          legacyName={formState.mode === 'edit' ? formState.card?.tecnico_nome_legado : null}
          onClose={() => setFormState(null)}
          onSave={saveForm}
          solicitantes={solicitantes}
          technicians={technicians}
          title={formState.mode === 'edit' ? 'Editar atualização' : formState.mode === 'duplicate' ? 'Duplicar atualização' : 'Nova atualização'}
        />
      )}
      {importOpen && <ChgImportPanel notify={notify} onClose={() => setImportOpen(false)} onDone={refresh} />}
      {confirm && (
        <ConfirmActionDialog busy={confirmBusy} busyLabel="Aguarde..." confirmLabel={confirm.confirmLabel} description={confirm.description} onCancel={() => setConfirm(null)} onConfirm={runConfirm} title={confirm.title} />
      )}
      {preview && (
        <Dialog description="O navegador bloqueou a cópia automática. Selecione a tabela e use Ctrl+C para colar no e-mail ou Teams." onClose={() => setPreview(null)} size="max-w-3xl" title="Tabela selecionada">
          <PreviewTable rows={preview.tabela} />
        </Dialog>
      )}
    </div>
  )
}
