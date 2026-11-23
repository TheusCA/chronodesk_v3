import { Table } from '../../components/ui/Table'
import { CHG_LABELS, SERVICE_LABELS, STAGE_TITLE, carteiraLabel, dashboard, dateTimeLabel, initials, shortLabel, sortByWindow, tecnicoName } from '../../lib/chg'
import { ChgCard } from './ChgCard'

// [9.17] Lista (renderList) e painel por técnico (renderDash) do v5.2. As
// barras usam larguras em passos de 5 % (sem atributo style); o número exato
// fica no texto.

const step5 = (fraction) => Math.max(5, Math.min(100, Math.round(fraction * 20) * 5))

export function ChgList({ cards }) {
  const rows = [...cards].sort(sortByWindow)
  const columns = [
    { key: 'etapa', label: 'Etapa', render: (c) => <span className={`chg-pill chg-tone-${c.view.stage}`}>{STAGE_TITLE[c.view.stage]}</span> },
    { key: 'solicitante', label: 'Solicitante', render: (c) => c.solicitante },
    { key: 'carteira', label: 'Carteira/código', render: (c) => <strong>{c.carteiras.map(carteiraLabel).join('; ')}</strong> },
    { key: 'local', label: 'Local', render: (c) => c.locais.join('; ') },
    { key: 'pas', label: 'PAs', render: (c) => c.qtd_pas || '' },
    { key: 'versao', label: 'Versão/caminho', render: (c) => <span className="font-mono text-xs">{c.versao}</span> },
    { key: 'inicio', label: 'Início SD', className: 'whitespace-nowrap', render: (c) => dateTimeLabel(c.sd_inicio) },
    { key: 'fim', label: 'Fim SD', className: 'whitespace-nowrap', render: (c) => dateTimeLabel(c.sd_fim) },
    { key: 'status', label: 'Status', render: (c) => SERVICE_LABELS[c.service_status] },
    { key: 'chg', label: 'CHG', render: (c) => (c.chg_numero ? `${c.chg_numero} (${CHG_LABELS[c.chg_status || '']})` : '—') },
    { key: 'tecnico', label: 'Técnico', render: (c) => tecnicoName(c) || '—' },
  ]
  if (!rows.length) return <p className="chg-empty">Nenhuma atualização encontrada com esses filtros.</p>
  return <div data-chg-list=""><Table caption="Lista de atualizações" cards columns={columns} rowKey={(c) => c.id} rows={rows} /></div>
}

export function ChgDashboard({ cards, withDone, onOpen }) {
  const data = dashboard(cards, withDone)
  if (!data.count) return <div className="chg-panel"><p className="chg-empty">Nenhuma atualização ativa com os filtros atuais.</p></div>
  const max = Math.max(1, ...data.rows.map((r) => r.total))
  const named = data.rows.filter((r) => r.name).length
  const legend = <div className="chg-legend">{data.keys.map((k) => <span className={`chg-tone-${k}`} key={k}>{STAGE_TITLE[k]} <b>{data.totals[k]}</b></span>)}</div>
  const segments = (by, total) => data.keys.filter((k) => by[k]).map((k) => (
    <span className={`chg-seg chg-tone-${k} chg-w-${step5(by[k] / total)}`} key={k} title={`${STAGE_TITLE[k]}: ${by[k]}`}>{by[k]}</span>
  ))
  const columns = [
    { key: 'tecnico', label: 'Técnico', render: (r) => <strong>{r.name || 'Sem técnico atribuído'}</strong> },
    ...data.keys.map((k) => ({ key: k, label: STAGE_TITLE[k], render: (r) => r.by[k] || '—' })),
    { key: 'total', label: 'Total', render: (r) => <strong>{r.total}</strong> },
    { key: 'pas', label: 'PAs ativas', render: (r) => r.pas || '—' },
    { key: 'next', label: 'Próxima janela', className: 'whitespace-nowrap', render: (r) => (r.next ? dateTimeLabel(r.next) : '—') },
  ]
  return (
    <div className="chg-dash" data-chg-dash="">
      <section className="chg-panel">
        <h3 className="font-semibold text-ink">Visão geral por etapa</h3>
        <p className="text-sm text-muted">{data.count} atualizações, {named} técnico{named === 1 ? '' : 's'} com atribuição, {data.unassigned.length} sem técnico{withDone ? '' : '. Concluídas ocultas pelo filtro'}</p>
        {legend}
        <div className="chg-stack">{segments(data.totals, data.count)}</div>
      </section>
      <div className="chg-dash-grid">
        <section className="chg-panel">
          <h3 className="font-semibold text-ink">CHGs por técnico</h3>
          <p className="text-sm text-muted">Cada cor é uma etapa; o número de cada uma fica na barra.</p>
          {legend}
          {data.rows.map((r) => (
            <div className="chg-trow" key={r.key}>
              <span className="chg-who"><span aria-hidden="true" className="chg-av">{r.name ? initials(r.name) : '?'}</span><span>{r.name || 'Sem técnico atribuído'}</span></span>
              <div className="chg-track"><div className={`chg-stack chg-w-${step5(r.total / max)}`}>{segments(r.by, r.total)}</div></div>
              <strong>{r.total}</strong>
            </div>
          ))}
        </section>
        <section className="chg-panel" data-chg-unassigned="">
          <h3 className="font-semibold text-ink">Sem técnico atribuído</h3>
          <p className="text-sm text-muted">Pela janela mais próxima. Clique para abrir.</p>
          {data.unassigned.length ? data.unassigned.slice(0, 8).map((c) => (
            <button className="chg-ua" key={c.id} onClick={() => onOpen(c)} type="button">
              <span><strong>{c.carteiras[0] ? carteiraLabel(c.carteiras[0]) : 'Carteira não informada'}{c.carteiras.length > 1 ? ` (+${c.carteiras.length - 1})` : ''}</strong><br /><small>{c.chg_numero || 'Sem CHG'}, início {shortLabel(c.sd_inicio)}</small></span>
              <span className={`chg-pill chg-tone-${c.view.stage}`}>{STAGE_TITLE[c.view.stage]}</span>
            </button>
          )) : <p className="chg-empty">Todas as atualizações ativas têm técnico.</p>}
          {data.unassigned.length > 8 && <p className="text-sm text-muted">E mais {data.unassigned.length - 8}.</p>}
        </section>
      </div>
      <section className="chg-panel">
        <h3 className="font-semibold text-ink">Detalhe por técnico</h3>
        <p className="text-sm text-muted">Quantidade por etapa, PAs das atualizações em aberto e a próxima janela de Service Desk.</p>
        <Table caption="Detalhe por técnico" cards columns={columns} rowKey={(r) => r.key} rows={data.rows} />
      </section>
    </div>
  )
}

/**
 * [Ajustes do CHG, D-CHG-37] Visão Etapa: uma etapa por vez na largura toda,
 * com os cards compactos numa grade que se ajusta à largura.
 */
export function ChgStageView({ cards, stages, stage, onStage, cardProps, drag }) {
  const current = stages.find((s) => s.key === stage) || stages[0]
  const stageCards = cards.filter((c) => c.view.stage === current.key).sort(sortByWindow)
  return (
    <section aria-label="Etapa" className="chg-stage-view" data-chg-stage-view="">
      <div aria-label="Etapa" className="chg-stage-tabs" role="group">
        {stages.map((s) => (
          <button aria-pressed={s.key === current.key} className={`chg-stage-tab chg-tone-${s.key}`} data-chg-stage-tab={s.key} key={s.key} onClick={() => onStage(s.key)} type="button">
            {s.title}<span className="chg-col-count">{cards.filter((c) => c.view.stage === s.key).length}</span>
          </button>
        ))}
      </div>
      <p className="chg-col-hint">{current.hint}</p>
      {stageCards.length
        ? <div className="chg-stage-grid">{stageCards.map((card) => <ChgCard card={card} drag={drag} key={card.id} {...cardProps} />)}</div>
        : <p className="chg-empty">{current.empty}</p>}
    </section>
  )
}
