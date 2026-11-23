import { useState } from 'react'
import { Icon } from '../../components/ui/Icon'
import {
  CHG_LABELS, PATCH_LABELS, PLAN_FIELDS, ROLLBACK_LABELS, SERVICE_LABELS, STAGES, STAGE_TITLE, TASKS, TIPO_LABELS,
  carteiraLabel, initials, maskDigits, parseMask, safeLink, shortLabel, tecnicoName, toMask,
} from '../../lib/chg'

// [9.17] Card do Controle de CHG: o conteúdo muda por etapa, como no v5.2. A
// etapa, o selo, o aviso e a barra de SLA vêm prontos do servidor (card.view).
// Sem can_manage, só leitura: nenhum editor, sem mover nem arrastar; copiar
// continua liberado.
// [Ajustes do CHG, D-CHG-34] No quadro, o card é compacto (ChgCard: carteira,
// CHG, tipo, prazos, situação, técnico e as ações rápidas); planos, endpoints,
// rollback e tarefas ficam no painel "Abrir" (ChgCardBody, no ChgPanel).

const PATCH_CLASS = { sucesso: 'chg-ps-ok', parcial: 'chg-ps-parcial', erro: 'chg-ps-erro' }

function CopyButton({ value, label, onCopy }) {
  const text = String(value || '').trim()
  const off = !text || ['N/A', 'NA'].includes(text.toUpperCase())
  return (
    <button aria-label={`Copiar ${label}`} className="chg-icon-btn" data-chg-copy={label} disabled={off} onClick={() => onCopy(text, label)} title={`Copiar ${label}`} type="button">
      <Icon className="h-4 w-4" name="file" />
    </button>
  )
}

function OpenLink({ url, label }) {
  const href = safeLink(url)
  if (!href) return <span aria-disabled="true" className="chg-icon-btn" title="Link indisponível"><Icon className="h-4 w-4" name="plug" /></span>
  return (
    <a aria-label={`Abrir ${label}`} className="chg-icon-btn" href={href} rel="noopener noreferrer" target="_blank" title={`Abrir ${label}`}>
      <Icon className="h-4 w-4" name="plug" />
    </a>
  )
}

function CopyLine({ label, value, link = false, onCopy }) {
  const text = String(value || '').trim()
  return (
    <div className="chg-copyline">
      <span className="chg-label">{label}</span>
      <span className={text ? 'chg-ct' : 'chg-ct chg-none'} title={text || undefined}>{text || 'Não informado'}</span>
      {link && <OpenLink label={label} url={text} />}
      <CopyButton label={label} onCopy={onCopy} value={text} />
    </div>
  )
}

// [D-CHG-35] Saindo do campo para o aviso de descarte ou para um controle que
// fecha o painel (X, Fechar, Editar, Duplicar), o campo não grava: fica
// pendente e a guarda pergunta. Gravando, o valor enviado passa a ser o
// carregado (o campo deixa de contar como pendente).
const LEAVING = '[role="alertdialog"], [data-chg-leave], [aria-label="Fechar painel"]'
const leaving = (event) => Boolean(event.relatedTarget?.closest?.(LEAVING))

/** Campo de texto que grava ao sair (como o v5.2), recarregado a cada versão do card. */
function BlurInput({ card, label, value, onSave, upper = false, maxLength = 40, placeholder = '' }) {
  return (
    <label className="field-group">
      <span className="label">{label}</span>
      <input
        className="field"
        data-chg-field=""
        defaultValue={value || ''}
        key={`${card.id}-${card.row_version}-${label}`}
        maxLength={maxLength}
        onBlur={(event) => {
          if (leaving(event)) return
          const next = upper ? event.target.value.trim().toUpperCase() : event.target.value.trim()
          if (next !== String(value || '')) {
            event.target.defaultValue = event.target.value
            onSave(next)
          }
        }}
        onInput={upper ? (event) => { event.target.value = event.target.value.toUpperCase() } : undefined}
        placeholder={placeholder}
      />
    </label>
  )
}

/** Data e hora com máscara dd/mm/aaaa hh:mm (só data = horário pendente, quando permitido). */
function MaskInput({ card, label, value, dateOnly = null, allowDateOnly = false, onSave, onInvalid }) {
  const initial = toMask(value, dateOnly)
  return (
    <label className="field-group">
      <span className={dateOnly ? 'label chg-hour-pending' : 'label'}>{label}</span>
      <input
        className="field"
        data-chg-field=""
        defaultValue={initial}
        inputMode="numeric"
        key={`${card.id}-${card.row_version}-${label}`}
        maxLength={16}
        onBlur={(event) => {
          if (leaving(event)) return
          const raw = event.target.value.trim()
          if (raw === initial) return
          const parsed = parseMask(raw, allowDateOnly)
          if (!parsed) {
            event.target.value = initial
            onInvalid(allowDateOnly ? 'Informe a data e a hora (dd/mm/aaaa hh:mm), ou deixe somente a data enquanto agenda o patch.' : 'Data e hora inválidas. Use dd/mm/aaaa hh:mm.')
            return
          }
          event.target.defaultValue = event.target.value
          onSave(parsed)
        }}
        onFocus={(event) => {
          if (/ --:--$/.test(event.target.value)) event.target.setSelectionRange(event.target.value.length - 5, event.target.value.length)
        }}
        onInput={(event) => {
          if (/--:--$/.test(event.target.value)) return
          event.target.value = maskDigits(event.target.value)
        }}
        placeholder={allowDateOnly ? 'dd/mm/aaaa --:--' : 'dd/mm/aaaa hh:mm'}
      />
    </label>
  )
}

function Carousel({ title, index, total, onPrev, onNext, onDelete = null, deleteLabel = '', rollback = false, children }) {
  return (
    <div className={rollback ? 'chg-carousel chg-carousel-rollback' : 'chg-carousel'}>
      <div className="chg-carousel-head">
        <span>{title}</span>
        <span className="chg-carousel-nav">
          {onDelete && <button aria-label={deleteLabel} className="chg-icon-btn" onClick={onDelete} title={deleteLabel} type="button"><Icon className="h-4 w-4" name="trash" /></button>}
          <button aria-label="Anterior" className="chg-icon-btn" disabled={index <= 0} onClick={onPrev} type="button">‹</button>
          <span className="chg-counter">{index + 1}/{total}</span>
          <button aria-label="Próximo" className="chg-icon-btn" disabled={index >= total - 1} onClick={onNext} type="button">›</button>
        </span>
      </div>
      {children}
    </div>
  )
}

function PlansCarousel({ card, editable, mode, onAct, onCopy, onConfirm }) {
  const [page, setPage] = useState(0)
  const index = Math.min(page, card.plans.length - 1)
  const plan = card.plans[index]
  const title = `${mode === 'tasks' ? 'Tasks para encerramento' : 'Planos da CHG'} · Plano ${index + 1}`
  const remove = editable && mode === 'edit' && index > 0
    ? () => onConfirm({ title: 'Excluir grupo de planos?', description: `O Plano ${index + 1}, com Implantação, Backup, Rollback e seus status, será removido. Os demais planos serão preservados.`, confirmLabel: 'Excluir', run: () => onAct(card, 'delete_plan', { plan: index }).then(() => setPage(index - 1)) })
    : null
  return (
    <Carousel deleteLabel={`Excluir Plano ${index + 1}`} index={index} onDelete={remove} onNext={() => setPage(index + 1)} onPrev={() => setPage(index - 1)} title={title} total={card.plans.length}>
      {mode === 'tasks' ? TASKS.map(([taskField, planField, label]) => (
        <div className="chg-task-row" key={taskField}>
          <span>{label}: <strong>{plan[planField] || 'Não informado'}</strong> <CopyButton label={label} onCopy={onCopy} value={plan[planField]} /></span>
          {editable ? (
            <select
              aria-label={`${label} do Plano ${index + 1}`}
              className="field chg-move"
              onChange={(event) => onAct(card, 'plan_task', { plan: index, task: taskField, value: event.target.value })}
              value={plan[taskField]}
            >
              <option value="pendente">Pendente</option>
              <option value="fechada">Fechada</option>
              {taskField === 'task_roll' && !card.rollback && <option value="iniciar_rollback">↶ Iniciar Rollback</option>}
            </select>
          ) : <span className={plan[taskField] === 'fechada' ? 'chg-task-ok' : ''}>{plan[taskField] === 'fechada' ? 'Fechada' : 'Pendente'}</span>}
        </div>
      )) : (
        <div className="chg-inline-2">
          {PLAN_FIELDS.map(([field, label]) => (editable
            ? <BlurInput card={card} key={field} label={label} onSave={(value) => onAct(card, 'plan_field', { plan: index, field, value })} placeholder="Nº do plano" upper value={plan[field]} />
            : <CopyLine key={field} label={label.replace('Plano de ', '')} onCopy={onCopy} value={plan[field]} />))}
        </div>
      )}
      {editable && mode === 'edit' && <button className="btn-secondary chg-btn" onClick={() => onAct(card, 'add_plan').then(() => setPage(card.plans.length))} type="button">+ Novo plano</button>}
    </Carousel>
  )
}

function RollbackPanel({ card, editable, nav, onAct, onCopy, onInvalid }) {
  const rb = card.rollback
  return (
    <>
      {nav}
      {editable ? <BlurInput card={card} label="Link do endpoint de rollback" maxLength={2048} onSave={(value) => onAct(card, 'rollback_field', { field: 'url', value })} value={rb.url} /> : <CopyLine label="Endpoint do rollback" link onCopy={onCopy} value={rb.url} />}
      {editable && <div className="chg-acts"><OpenLink label="endpoint do rollback" url={rb.url} /><CopyButton label="link do rollback" onCopy={onCopy} value={rb.url} /></div>}
      {editable ? <MaskInput card={card} label="Início do rollback" onInvalid={onInvalid} onSave={(value) => onAct(card, 'rollback_field', { field: 'start', value })} value={rb.start_at} /> : <p className="text-sm">Início: <strong>{shortLabel(rb.start_at)}</strong></p>}
      {editable ? (
        <label className="field-group">
          <span className="label">Situação do rollback</span>
          <select className="field" onChange={(event) => onAct(card, 'rollback_field', { field: 'status', value: event.target.value })} value={rb.status}>
            {Object.entries(ROLLBACK_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
      ) : <span className="chg-pill chg-tone-pink">Rollback · {ROLLBACK_LABELS[rb.status]}</span>}
    </>
  )
}

function AttemptPanel({ card, attempt, past, editable, nav, onAct, onCopy, onInvalid }) {
  const endpoints = attempt.endpoints.length || past ? attempt.endpoints : [{ url: '', start_at: attempt.reagendando ? null : card.sd_inicio, start_date: null }]
  const multi = endpoints.length > 1
  const label = (i) => `${attempt.attempt_no > 1 ? `${attempt.attempt_no}º ` : ''}Patch${multi ? ` ${i + 1}` : ''}`
  const statusTarget = past ? { action: 'history_status', data: { attempt_no: attempt.attempt_no } } : { action: 'set_field', data: { field: 'patch_status' } }
  return (
    <>
      {nav}
      {endpoints.map((endpoint, i) => (
        <div className="chg-inline" key={`${attempt.attempt_no}-${i}`}>
          {editable && !past ? (
            <>
              <BlurInput card={card} label={`Link do endpoint ${i + 1}`} maxLength={2048} onSave={(value) => onAct(card, 'endpoint_field', { index: i, field: 'url', value })} value={endpoint.url} />
              <div className="chg-acts">
                <OpenLink label={`endpoint ${i + 1}`} url={endpoint.url} />
                <CopyButton label={`link do endpoint ${i + 1}`} onCopy={onCopy} value={endpoint.url} />
                {endpoints.length > 1 && <button aria-label={`Remover endpoint ${i + 1}`} className="chg-icon-btn" onClick={() => onAct(card, 'delete_endpoint', { index: i })} type="button"><Icon className="h-4 w-4" name="trash" /></button>}
              </div>
              <MaskInput allowDateOnly card={card} dateOnly={endpoint.start_date} label={`Início do ${label(i).toLowerCase()}`} onInvalid={onInvalid} onSave={(value) => onAct(card, 'endpoint_field', { index: i, field: 'start', value })} value={endpoint.start_at} />
            </>
          ) : (
            <>
              <CopyLine label={label(i)} link onCopy={onCopy} value={endpoint.url} />
              <span className="text-sm">{endpoint.start_at ? shortLabel(endpoint.start_at) : endpoint.start_date ? `${toMask(null, endpoint.start_date).slice(0, 10)} · horário pendente` : 'Sem data'}</span>
            </>
          )}
        </div>
      ))}
      {!past && card.view.stage === 'prep' && card.view.badge.text === 'Novo patch · horário pendente' && <p className="chg-hint">Data preenchida. Informe o horário do novo patch para programar a execução automática.</p>}
      {!past && attempt.patch_status === 'erro' && editable && <p className="chg-hint">Patch com erro. Clique em + Novo patch para registrar outra tentativa.</p>}
      <div className="chg-acts">
        {editable && !past && (card.view.can_new_patch
          ? <button className="btn-secondary chg-btn" onClick={() => onAct(card, 'new_patch')} type="button">+ Novo patch</button>
          : card.view.stage !== 'valid' && <button className="btn-secondary chg-btn" onClick={() => onAct(card, 'add_endpoint')} type="button">+ Adicionar endpoint</button>)}
        {editable ? (
          <label className="field-group">
            <span className="label">Status do Patch {attempt.attempt_no}</span>
            <select className={`field ${PATCH_CLASS[attempt.patch_status] || ''}`} onChange={(event) => onAct(card, statusTarget.action, { ...statusTarget.data, value: event.target.value })} value={attempt.patch_status || ''}>
              {Object.entries(PATCH_LABELS).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
          </label>
        ) : <span className={`chg-pill ${PATCH_CLASS[attempt.patch_status] || 'chg-tone-info'}`}>{PATCH_LABELS[attempt.patch_status || '']}</span>}
        {attempt.registered_at && <span className="text-xs text-muted">registrada em {shortLabel(attempt.registered_at)}</span>}
      </div>
    </>
  )
}

/** Carrossel rollback/patches: o rollback ativo primeiro, depois Patch 1, 2, 3... */
function TimelineCarousel({ card, editable, readOnlyEndpoints, onAct, onCopy, onConfirm, onInvalid }) {
  const pages = [...(card.rollback ? [{ kind: 'rollback' }] : []), ...card.attempts.map((attempt, i) => ({ kind: 'patch', attempt, past: i < card.attempts.length - 1 }))]
  const defaultPage = card.rollback ? 0 : pages.length - 1
  const [chosen, setChosen] = useState(null)
  const index = Math.min(chosen ?? defaultPage, pages.length - 1)
  const page = pages[index]
  const title = page.kind === 'rollback' ? '↶ Rollback · endpoint e início' : pages.length > 1 ? `Patch ${page.attempt.attempt_no} · ${page.past ? 'histórico' : 'atual'}` : 'Endpoints e início do patch'
  const remove = !editable ? null : page.kind === 'rollback'
    ? () => onConfirm({ title: 'Excluir card de rollback?', description: 'O link e o início do rollback serão removidos. Os patches continuarão intactos e o card permanecerá na etapa atual.', confirmLabel: 'Excluir', run: () => onAct(card, 'delete_rollback').then(() => setChosen(null)) })
    : pages.length > 1 ? () => onConfirm({ title: 'Excluir tentativa?', description: `A ${page.attempt.attempt_no}ª tentativa de ${card.attempts.length}, com os endpoints, as datas e o status dela, será removida. As outras tentativas continuam.`, confirmLabel: 'Excluir', run: () => onAct(card, 'delete_attempt', { attempt_no: page.attempt.attempt_no }).then(() => setChosen(null)) }) : null
  return (
    <Carousel deleteLabel={page.kind === 'rollback' ? 'Excluir rollback' : `Excluir tentativa ${page.attempt?.attempt_no}`} index={index} onDelete={remove} onNext={() => setChosen(index + 1)} onPrev={() => setChosen(index - 1)} rollback={page.kind === 'rollback'} title={title} total={pages.length}>
      {page.kind === 'rollback'
        ? <RollbackPanel card={card} editable={editable} onAct={onAct} onCopy={onCopy} onInvalid={onInvalid} />
        : <AttemptPanel attempt={page.attempt} card={card} editable={editable && !readOnlyEndpoints} onAct={onAct} onCopy={onCopy} onInvalid={onInvalid} past={page.past || readOnlyEndpoints} />}
      {readOnlyEndpoints && editable && page.kind === 'patch' && !page.past && (
        <label className="field-group">
          <span className="label">Status do patch</span>
          <select className={`field ${PATCH_CLASS[page.attempt.patch_status] || ''}`} onChange={(event) => onAct(card, 'set_field', { field: 'patch_status', value: event.target.value })} value={page.attempt.patch_status || ''}>
            {Object.entries(PATCH_LABELS).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
          </select>
        </label>
      )}
    </Carousel>
  )
}

function SlaBar({ sla }) {
  if (!sla) return null
  return (
    <div className="chg-sla" title={`SLA: Início ao Fim do Service Desk, ${sla.percent}% percorrido`}>
      <div className="chg-sla-track">
        <progress aria-label="Percentual do SLA" max={100} value={sla.percent} />
        {sla.ticks.map((tick, i) => <span aria-label={tick.label} className={`chg-tick chg-at-${tick.step} chg-tc-${tick.color} chg-tick-${tick.state}`} key={i} role="img" title={tick.label} />)}
      </div>
      <div className="chg-sla-legend"><span>{sla.percent}% do SLA</span><span>{sla.left}</span></div>
      {sla.lines.length ? sla.lines.map((line, i) => <div className={`chg-sla-line chg-tc-${line.color}`} key={i}>{line.text}</div>) : <div className="chg-sla-line">Sem data de patch</div>}
    </div>
  )
}

function TecnicoSelect({ card, technicians, onAct }) {
  const value = card.tecnico_id ? String(card.tecnico_id) : card.tecnico_nome_legado ? 'historico' : ''
  return (
    <label className="field-group">
      <span className="label">Técnico responsável</span>
      <select className="field" onChange={(event) => onAct(card, 'set_field', { field: 'tecnico_id', value: event.target.value === '' ? null : Number(event.target.value) })} value={value}>
        <option value="">Sem técnico</option>
        {card.tecnico_nome_legado && <option disabled value="historico">{card.tecnico_nome_legado} (histórico)</option>}
        {card.tecnico_id && !technicians.some((t) => t.id === card.tecnico_id) && <option value={String(card.tecnico_id)}>{card.tecnico_nome || `ID ${card.tecnico_id}`} (arquivado)</option>}
        {technicians.map((t) => <option key={t.id} value={String(t.id)}>{t.nome}</option>)}
      </select>
    </label>
  )
}

function ChgControls({ card, onAct }) {
  return (
    <div className="chg-inline-2">
      <BlurInput card={card} label="Nº da CHG" onSave={(value) => onAct(card, 'set_field', { field: 'chg_numero', value })} placeholder="CHG…" upper value={card.chg_numero} />
      <label className="field-group">
        <span className="label">Status da CHG</span>
        <select className="field" onChange={(event) => onAct(card, 'set_field', { field: 'chg_status', value: event.target.value })} value={card.chg_status || ''}>
          {Object.entries(CHG_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </label>
    </div>
  )
}

function Impact({ card }) {
  const carteiras = card.carteiras
  const locais = card.locais
  return (
    <>
      {carteiras.length < 2 ? <h3 className="chg-card-title">{carteiras.length ? carteiraLabel(carteiras[0]) : 'Carteira não informada'}</h3> : (
        <details className="chg-details">
          <summary title={`Ver as ${carteiras.length} carteiras impactadas`}>Carteiras impactadas · {carteiras.length}: {carteiraLabel(carteiras[0])} <span className="chg-col-count">+{carteiras.length - 1}</span></summary>
          <div className="chg-entries">
            {carteiras.map((c, i) => <div className="chg-entry" key={i}><span className="chg-code">{c.codigo || '—'}</span><span>{c.nome}{!c.codigo && ' (sem código)'}</span>{c.pas !== null && <span className="chg-col-count">{c.pas} PAs</span>}</div>)}
          </div>
        </details>
      )}
      {card.descricao && <p className="chg-desc" title={card.descricao}>{card.descricao}</p>}
      <dl className="chg-facts">
        <div><dt>{locais.length > 1 ? 'Locais' : 'Local'}</dt><dd>{locais.length < 2 ? (locais[0] || '—') : (
          <details className="chg-details"><summary>{locais[0]} <span className="chg-col-count">+{locais.length - 1}</span></summary><div className="chg-entries">{locais.map((l) => <span key={l}>{l}</span>)}</div></details>
        )}</dd></div>
        <div><dt>PAs</dt><dd>{card.qtd_pas || '—'}</dd></div>
        <div><dt>Tipo</dt><dd>{TIPO_LABELS[card.tipo]}</dd></div>
      </dl>
    </>
  )
}

/** O card inteiro, com os editores da etapa: o conteúdo do painel "Abrir". */
export function ChgCardBody({ card, canManage, technicians, onAct, onCopy, onCopyTable, onConfirm, onInvalid }) {
  const { view } = card
  const stage = view.stage
  const editable = canManage
  const rollbackTone = card.rollback ? 'chg-tone-pink' : `chg-tone-${stage}`
  const chgRef = card.chg_numero
    ? <span className="chg-card-top"><strong>{card.chg_numero}</strong><CopyButton label="número da CHG" onCopy={onCopy} value={card.chg_numero} /></span>
    : <span className="chg-none">{stage === 'cancel' ? 'Solicitação' : 'Sem CHG'}</span>
  const tech = tecnicoName(card)
  let inline = null
  if (stage === 'comite') {
    inline = (
      <div className="chg-inline">
        {editable && <ChgControls card={card} onAct={onAct} />}
        <PlansCarousel card={card} editable={editable} mode="edit" onAct={onAct} onConfirm={onConfirm} onCopy={onCopy} />
        {editable && <TecnicoSelect card={card} onAct={onAct} technicians={technicians} />}
        {view.note && <p className="chg-note">{view.note}</p>}
      </div>
    )
  } else if (stage === 'prep' || stage === 'exec') {
    inline = (
      <div className="chg-inline">
        <PlansCarousel card={card} editable={editable} mode="edit" onAct={onAct} onConfirm={onConfirm} onCopy={onCopy} />
        <TimelineCarousel card={card} editable={editable} onAct={onAct} onConfirm={onConfirm} onCopy={onCopy} onInvalid={onInvalid} />
        {editable && stage === 'prep' && <TecnicoSelect card={card} onAct={onAct} technicians={technicians} />}
      </div>
    )
  } else if (stage === 'valid') {
    inline = (
      <div className="chg-inline">
        <TimelineCarousel card={card} editable={editable} onAct={onAct} onConfirm={onConfirm} onCopy={onCopy} onInvalid={onInvalid} readOnlyEndpoints />
        <PlansCarousel card={card} editable={editable} mode="tasks" onAct={onAct} onConfirm={onConfirm} onCopy={onCopy} />
        {editable && <button className="btn-primary chg-btn" data-chg-finish={card.id} onClick={() => onAct(card, 'finish')} type="button">Concluir execução</button>}
      </div>
    )
  } else if (stage === 'done') {
    inline = (
      <div className="chg-inline">
        <PlansCarousel card={card} editable={false} mode="edit" onAct={onAct} onConfirm={onConfirm} onCopy={onCopy} />
        {editable && <button className="btn-secondary chg-btn" onClick={() => onAct(card, 'reopen')} type="button">Reabrir execução</button>}
      </div>
    )
  } else if (editable) {
    inline = <div className="chg-inline"><ChgControls card={card} onAct={onAct} /></div>
  }

  return (
    <div className={`chg-detail ${rollbackTone}${view.late ? ' chg-card-late' : ''}`} data-chg-detail={card.id} data-chg-stage={stage}>
      <div className="chg-card-top">
        {chgRef}
        <span className={`chg-pill chg-tone-${view.badge.tone}`} data-chg-badge="">{view.badge.text}</span>
        {card.rollback && stage !== 'exec' && <span className="chg-pill chg-tone-pink">Rollback · {ROLLBACK_LABELS[card.rollback.status]}</span>}
        {['prep', 'exec', 'valid', 'done'].includes(stage) && <span className="text-sm text-muted">{tech ? <>Técnico: <strong>{tech}</strong></> : 'Sem técnico responsável'}</span>}
        {view.is_manual && editable && <button className="chg-mtag" data-chg-auto={card.id} onClick={() => onAct(card, 'auto')} title={`Etapa definida manualmente. Clique para voltar à etapa automática (${STAGE_TITLE[view.auto_stage]}).`} type="button">Movido manualmente ↺</button>}
      </div>
      {stage === 'exec' && <SlaBar sla={view.sla} />}
      <Impact card={card} />
      {stage === 'comite' && (editable ? (
        <label className="field-group">
          <span className="label">Aprovação Service</span>
          <select className="field" onChange={(event) => onAct(card, 'set_field', { field: 'service_status', value: event.target.value })} value={card.service_status}>
            {Object.entries(SERVICE_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
      ) : <p className="text-sm">Aprovação Service: <strong>{SERVICE_LABELS[card.service_status]}</strong></p>)}
      <div className="chg-window">
        <div><small>Início Service Desk</small><strong>{shortLabel(card.sd_inicio)}</strong></div>
        <div><small>Fim Service Desk</small><strong>{shortLabel(card.sd_fim)}</strong></div>
      </div>
      <CopyLine label="Versão" onCopy={onCopy} value={card.versao} />
      <CopyLine label="Versão anterior" onCopy={onCopy} value={card.versao_anterior} />
      <CopyLine label="Planilha PAs" link onCopy={onCopy} value={card.link_planilha} />
      {inline}
      <div className="chg-foot">
        <span className="chg-who"><span aria-hidden="true" className="chg-av">{initials(card.solicitante)}</span><span>{card.solicitante || 'Sem solicitante'}</span></span>
        <span className="chg-acts">
          {editable && <MoveSelect card={card} onAct={onAct} />}
          <button aria-label="Copiar tabela" className="chg-icon-btn" onClick={() => onCopyTable(card)} title="Copiar tabela" type="button"><Icon className="h-4 w-4" name="file" /></button>
        </span>
      </div>
    </div>
  )
}

/** Mover para outra etapa: o select do card compacto e do painel. */
function MoveSelect({ card, onAct, marker = false }) {
  return (
    <select aria-label="Mover para outra coluna" className="field chg-move" data-chg-move={marker ? card.id : undefined} onChange={(event) => { const value = event.target.value; event.target.value = ''; if (value) onAct(card, 'move', { stage: value }) }} value="">
      <option disabled value="">Mover para…</option>
      {STAGES.filter((s) => s.key !== card.view.stage).map((s) => <option key={s.key} value={s.key}>{s.title}</option>)}
    </select>
  )
}

/**
 * [D-CHG-34] Card compacto do quadro e da visão Etapa: carteira(s), número da
 * CHG, tipo, prazos, situação e técnico; as ações rápidas (mover, copiar a
 * tabela, copiar a CHG) e "Abrir", que leva ao painel com os editores.
 */
export function ChgCard({ card, canManage, onAct, onCopy, onCopyTable, onOpen, drag = null }) {
  const { view } = card
  const stage = view.stage
  const editable = canManage
  const first = card.carteiras[0] ? carteiraLabel(card.carteiras[0]) : 'Carteira não informada'
  const tech = tecnicoName(card)
  return (
    <article
      aria-label={`${STAGE_TITLE[stage]}: ${first}`}
      className={`chg-card ${card.rollback ? 'chg-tone-pink' : `chg-tone-${stage}`}${view.late ? ' chg-card-late' : ''}`}
      data-chg-card={card.id}
      data-chg-stage={stage}
      draggable={editable && Boolean(drag)}
      onDragStart={editable && drag ? (event) => drag.start(event, card) : undefined}
    >
      <div className="chg-card-top">
        {card.chg_numero
          ? <span className="chg-card-top"><strong>{card.chg_numero}</strong><CopyButton label="número da CHG" onCopy={onCopy} value={card.chg_numero} /></span>
          : <span className="chg-none">{stage === 'cancel' ? 'Solicitação' : 'Sem CHG'}</span>}
        <span className={`chg-pill chg-tone-${view.badge.tone}`} data-chg-badge="">{view.badge.text}</span>
        {card.rollback && stage !== 'exec' && <span className="chg-pill chg-tone-pink">Rollback · {ROLLBACK_LABELS[card.rollback.status]}</span>}
        {view.is_manual && editable && <button className="chg-mtag" data-chg-auto={card.id} onClick={() => onAct(card, 'auto')} title={`Etapa definida manualmente. Clique para voltar à etapa automática (${STAGE_TITLE[view.auto_stage]}).`} type="button">Movido manualmente ↺</button>}
      </div>
      <h3 className="chg-card-title" title={card.carteiras.map(carteiraLabel).join('; ')}>{first}{card.carteiras.length > 1 && <span className="chg-col-count">+{card.carteiras.length - 1}</span>}</h3>
      {stage === 'exec' && <SlaBar sla={view.sla} />}
      <dl className="chg-kv">
        <div><dt>Tipo</dt><dd>{TIPO_LABELS[card.tipo]}</dd></div>
        <div><dt>Técnico</dt><dd>{tech || 'Sem técnico'}</dd></div>
        <div><dt>Início Service Desk</dt><dd>{shortLabel(card.sd_inicio)}</dd></div>
        <div><dt>Fim Service Desk</dt><dd>{shortLabel(card.sd_fim)}</dd></div>
      </dl>
      <div className="chg-foot chg-foot-compact">
        {editable && <MoveSelect card={card} marker onAct={onAct} />}
        <span className="chg-acts">
          <button className="btn-secondary chg-btn" data-chg-open={card.id} onClick={(event) => onOpen(card, event.currentTarget)} type="button">Abrir</button>
          <button aria-label="Copiar tabela" className="chg-icon-btn" data-chg-copy-table={card.id} onClick={() => onCopyTable(card)} title="Copiar tabela" type="button"><Icon className="h-4 w-4" name="file" /></button>
        </span>
      </div>
    </article>
  )
}
