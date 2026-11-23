import { useMemo, useRef, useState } from 'react'
import { ConfirmDiscardDialog } from '../../components/ui/ConfirmDiscardDialog'
import { Drawer } from '../../components/ui/Drawer'
import { Field } from '../../components/ui/Field'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { CHG_LABELS, PLAN_FIELDS, SERVICE_LABELS, TIPO_LABELS, maskDigits, parseMask, pasSum, plus24h, toMask } from '../../lib/chg'
import { catalogCodeForName, emptyPlan, formFromCard, payloadFromForm } from './chgFormModel'

// [9.17] Formulário do card (novo, editar, duplicar) no Drawer, com a guarda
// de alteração não salva do portal. Início do Service Desk vem da sugestão e
// o fim é o início + 24 h enquanto ninguém mexe neles (v5.2). O servidor
// valida tudo de novo; aqui só a forma.

export function ChgForm({ title, card, legacyName = null, catalog, technicians, solicitantes = [], onSave, onClose }) {
  const initial = useMemo(() => formFromCard(card), [card])
  const [form, setForm] = useState(initial)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [plan, setPlan] = useState(0)
  const auto = useRef({ inicio: initial.inicio && initial.inicio === initial.sugestao ? initial.inicio : '', fim: initial.fim && initial.inicio && parseMask(initial.fim) === plus24h(parseMask(initial.inicio) || '') ? initial.fim : '' })
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)
  const { requestLeave, dialogProps } = useUnsavedChangesGuard(dirty)
  const catalogByCode = useMemo(() => new Map((catalog?.carteiras || []).filter((c) => c.ativo).map((c) => [c.codigo, c.nome])), [catalog])
  const set = (patch) => setForm((current) => ({ ...current, ...patch }))
  const setCarteira = (i, patch) => set({ carteiras: form.carteiras.map((c, k) => (k === i ? { ...c, ...patch } : c)) })
  const setPlanField = (field, value) => set({ plans: form.plans.map((p, k) => (k === plan ? { ...p, [field]: value.toUpperCase() } : p)) })

  function withAutoWindow(next) {
    // Sugestão preenche o início (enquanto o início não foi mexido) e o fim = início + 24 h.
    const patch = { ...next }
    const sugestao = parseMask(patch.sugestao ?? form.sugestao)
    if ('sugestao' in next && sugestao && (!form.inicio || form.inicio === auto.current.inicio)) {
      patch.inicio = toMask(sugestao)
      auto.current.inicio = patch.inicio
    }
    const inicio = parseMask(patch.inicio ?? form.inicio)
    if (('inicio' in patch) && inicio && (!form.fim || form.fim === auto.current.fim)) {
      patch.fim = toMask(plus24h(inicio))
      auto.current.fim = patch.fim
    }
    set(patch)
  }

  async function save() {
    const [payload, message] = payloadFromForm(form)
    if (!payload) {
      setError(message)
      return
    }
    setError(null)
    setBusy(true)
    const ok = await onSave(payload)
    setBusy(false)
    if (ok) onClose()
  }

  const mask = (field) => ({
    inputMode: 'numeric',
    maxLength: 16,
    placeholder: 'dd/mm/aaaa hh:mm',
    value: form[field],
    onChange: (event) => withAutoWindow({ [field]: maskDigits(event.target.value) }),
  })
  const sum = pasSum(form.carteiras)

  return (
    <>
      <Drawer
        footer={(
          <>
            <button className="btn-secondary chg-btn" onClick={() => requestLeave(onClose)} type="button">Cancelar</button>
            <button className="btn-primary chg-btn" data-chg-save="" disabled={busy} onClick={save} type="button">{busy ? 'Salvando...' : 'Salvar atualização'}</button>
          </>
        )}
        onClose={() => requestLeave(onClose)}
        size="max-w-3xl"
        title={title}
      >
        <div className="chg-form" data-chg-form="">
          {error && <p className="field-error" role="alert">{error}</p>}
          <h3 className="font-semibold text-ink">Solicitação</h3>
          <div className="chg-form-grid">
            <Field label="Solicitante *"><input list="chg-solicitantes" maxLength={100} onChange={(e) => set({ solicitante: e.target.value })} value={form.solicitante} /></Field>
            <Field label="Tipo de execução"><select onChange={(e) => set({ tipo: e.target.value })} value={form.tipo}>{Object.entries(TIPO_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></Field>
          </div>
          <div className="chg-multi">
            <div className="chg-multi-head"><span>Carteiras impactadas *</span><span aria-live="polite" className="text-sm text-muted">{sum ? `Total: ${sum} PAs` : ''}</span></div>
            {form.carteiras.map((c, i) => (
              <div className="chg-form-grid" data-chg-carteira={i} key={i}>
                <Field help={!c.sem_codigo && c.codigo && !catalogByCode.has(c.codigo.trim()) ? 'Código fora da lista padrão. Informe ou ajuste o nome.' : null} label={`Código da carteira ${i + 1}`}>
                  <input disabled={c.sem_codigo} inputMode="numeric" list="chg-codigos" maxLength={10} onChange={(e) => { const codigo = e.target.value.replace(/\D/g, ''); const nome = catalogByCode.get(codigo); setCarteira(i, nome ? { codigo, nome } : { codigo }) }} placeholder={c.sem_codigo ? 'Sem código' : 'Ex.: 372'} value={c.codigo} />
                </Field>
                <Field label={`Nome da carteira ${i + 1} *`}><input list="chg-nomes" maxLength={120} onChange={(e) => { const nome = e.target.value; const codigo = !c.sem_codigo && !c.codigo.trim() ? catalogCodeForName(catalog?.carteiras, nome) : ''; setCarteira(i, codigo ? { nome, codigo } : { nome }) }} value={c.nome} /></Field>
                <Field label="PAs (opcional)"><input inputMode="numeric" maxLength={5} onChange={(e) => setCarteira(i, { pas: e.target.value.replace(/\D/g, '') })} value={c.pas} /></Field>
                <label className="chg-check"><input checked={c.sem_codigo} onChange={(e) => setCarteira(i, { sem_codigo: e.target.checked, codigo: e.target.checked ? '' : c.codigo })} type="checkbox" />Esta carteira não tem código</label>
                <button className="btn-secondary chg-btn" disabled={form.carteiras.length === 1} onClick={() => set({ carteiras: form.carteiras.filter((_, k) => k !== i) })} type="button">Remover carteira {i + 1}</button>
              </div>
            ))}
            <button className="btn-secondary chg-btn" disabled={form.carteiras.length >= 50} onClick={() => set({ carteiras: [...form.carteiras, { codigo: '', nome: '', sem_codigo: false, pas: '' }] })} type="button">+ Adicionar carteira</button>
          </div>
          <div className="chg-multi">
            <div className="chg-multi-head"><span>Locais impactados *</span></div>
            {form.locais.map((local, i) => (
              <div className="chg-form-grid" key={i}>
                <Field label={`Local ${i + 1}`}><input list="chg-locais" maxLength={60} onChange={(e) => set({ locais: form.locais.map((l, k) => (k === i ? e.target.value : l)) })} value={local} /></Field>
                <button className="btn-secondary chg-btn" disabled={form.locais.length === 1} onClick={() => set({ locais: form.locais.filter((_, k) => k !== i) })} type="button">Remover local {i + 1}</button>
              </div>
            ))}
            <button className="btn-secondary chg-btn" disabled={form.locais.length >= 20} onClick={() => set({ locais: [...form.locais, ''] })} type="button">+ Adicionar local</button>
          </div>
          <div className="chg-form-grid">
            <Field help="Texto puro (caminho de rede não vira link)." label="Versão/caminho"><input maxLength={500} onChange={(e) => set({ versao: e.target.value })} value={form.versao} /></Field>
            <Field label="Versão anterior do software"><input maxLength={500} onChange={(e) => set({ versao_anterior: e.target.value })} value={form.versao_anterior} /></Field>
            <Field label="Descrição resumida"><textarea maxLength={300} onChange={(e) => set({ descricao: e.target.value })} rows={2} value={form.descricao} /></Field>
            <Field help="Endereço completo, com https://." label="Link planilha com PAs"><input maxLength={2048} onChange={(e) => set({ link_planilha: e.target.value })} type="url" value={form.link_planilha} /></Field>
          </div>
          <h3 className="font-semibold text-ink">Janela</h3>
          <div className="chg-form-grid">
            <Field help="Preenche o início do Service Desk e o fim 24h depois." label="Data sugestão *"><input {...mask('sugestao')} /></Field>
            <Field label="Aprovação Service"><select onChange={(e) => set({ service_status: e.target.value })} value={form.service_status}>{Object.entries(SERVICE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></Field>
            <Field label="Início Service Desk"><input {...mask('inicio')} /></Field>
            <Field label="Fim Service Desk"><input {...mask('fim')} /></Field>
          </div>
          <h3 className="font-semibold text-ink">CHG e execução</h3>
          <div className="chg-form-grid">
            <Field label="CHG"><input maxLength={40} onChange={(e) => set({ chg_numero: e.target.value.toUpperCase() })} placeholder="Ex.: CHG0012345" value={form.chg_numero} /></Field>
            <Field label="Status da CHG"><select onChange={(e) => set({ chg_status: e.target.value })} value={form.chg_status}>{Object.entries(CHG_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></Field>
            <Field label="Técnico responsável">
              <select onChange={(e) => set({ tecnico_id: e.target.value })} value={form.tecnico_id}>
                <option value="">Sem técnico</option>
                {legacyName && <option value="historico">{legacyName} (histórico)</option>}
                {technicians.map((t) => <option key={t.id} value={String(t.id)}>{t.nome}</option>)}
              </select>
            </Field>
          </div>
          <div className="chg-multi">
            <div className="chg-multi-head">
              <span>Planos da CHG · Plano {plan + 1}</span>
              <span className="chg-carousel-nav">
                {plan > 0 && <button className="btn-secondary chg-btn" onClick={() => { set({ plans: form.plans.filter((_, k) => k !== plan) }); setPlan(plan - 1) }} type="button">Excluir Plano {plan + 1}</button>}
                <button aria-label="Plano anterior" className="chg-icon-btn" disabled={plan === 0} onClick={() => setPlan(plan - 1)} type="button">‹</button>
                <span className="chg-counter">{plan + 1}/{form.plans.length}</span>
                <button aria-label="Próximo plano" className="chg-icon-btn" disabled={plan >= form.plans.length - 1} onClick={() => setPlan(plan + 1)} type="button">›</button>
              </span>
            </div>
            <div className="chg-form-grid">
              {PLAN_FIELDS.map(([field, label]) => <Field key={field} label={label}><input maxLength={40} onChange={(e) => setPlanField(field, e.target.value)} placeholder="Nº do plano" value={form.plans[plan][field]} /></Field>)}
            </div>
            <button className="btn-secondary chg-btn" disabled={form.plans.length >= 20} onClick={() => { set({ plans: [...form.plans, emptyPlan()] }); setPlan(form.plans.length) }} type="button">+ Novo plano</button>
          </div>
          <datalist id="chg-solicitantes">{solicitantes.map((s) => <option key={s} value={s} />)}</datalist>
          <datalist id="chg-codigos">{(catalog?.carteiras || []).filter((c) => c.ativo).map((c) => <option key={c.id} label={c.nome} value={c.codigo} />)}</datalist>
          <datalist id="chg-nomes">{(catalog?.carteiras || []).filter((c) => c.ativo).map((c) => <option key={c.id} value={c.nome} />)}</datalist>
          <datalist id="chg-locais">{(catalog?.locais || []).filter((l) => l.ativo).map((l) => <option key={l.id} value={l.nome} />)}</datalist>
        </div>
      </Drawer>
      <ConfirmDiscardDialog {...dialogProps} />
    </>
  )
}
