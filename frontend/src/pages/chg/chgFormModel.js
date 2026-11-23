import { parseMask, toMask } from '../../lib/chg'

// [9.17] Modelo do formulário do Controle de CHG (sem componentes): do card
// para o formulário e do formulário para o corpo do POST.

export const emptyPlan = () => ({ implantacao: '', backup: '', rollback: '', task_impl: 'pendente', task_bkp: 'pendente', task_roll: 'pendente' })

export function formFromCard(card) {
  const base = card || {}
  return {
    solicitante: base.solicitante || '',
    tipo: base.tipo || 'padrao',
    carteiras: (base.carteiras?.length ? base.carteiras : [{ codigo: '', nome: '', pas: null }]).map((c) => ({ codigo: c.codigo || '', nome: c.nome || '', sem_codigo: Boolean(base.carteiras?.length) && !c.codigo, pas: c.pas ?? '' })),
    locais: base.locais?.length ? [...base.locais] : [''],
    versao: base.versao || '',
    versao_anterior: base.versao_anterior || '',
    descricao: base.descricao || '',
    link_planilha: base.link_planilha || '',
    sugestao: toMask(base.sugestao_at),
    service_status: base.service_status || 'aguardando',
    inicio: toMask(base.sd_inicio),
    fim: toMask(base.sd_fim),
    chg_numero: base.chg_numero || '',
    chg_status: base.chg_status || '',
    tecnico_id: base.tecnico_id ? String(base.tecnico_id) : base.tecnico_nome_legado ? 'historico' : '',
    plans: (base.plans?.length ? base.plans : [emptyPlan()]).map((p) => ({ ...emptyPlan(), ...p })),
  }
}

/** O corpo do POST a partir do formulário; null + mensagem se a forma não fecha. */
export function payloadFromForm(form) {
  const sugestao = parseMask(form.sugestao)
  if (!form.solicitante.trim()) return [null, 'Informe o solicitante.']
  if (!sugestao) return [null, 'Informe a data sugestão (dd/mm/aaaa hh:mm).']
  const inicio = form.inicio.trim() ? parseMask(form.inicio) : null
  const fim = form.fim.trim() ? parseMask(form.fim) : null
  if (form.inicio.trim() && !inicio) return [null, 'Início do Service Desk inválido (dd/mm/aaaa hh:mm).']
  if (form.fim.trim() && !fim) return [null, 'Fim do Service Desk inválido (dd/mm/aaaa hh:mm).']
  if (inicio && fim && fim < inicio) return [null, 'O fim do Service Desk não pode ser antes do início.']
  if (form.chg_status === 'aprovada' && !form.chg_numero.trim()) return [null, 'Informe o número da CHG antes de marcá-la como aprovada.']
  const seen = new Set()
  for (const [i, local] of form.locais.entries()) {
    const key = local.trim().toLocaleLowerCase('pt-BR')
    if (!key) return [null, `Informe o local ${i + 1} ou remova a linha vazia.`]
    if (seen.has(key)) return [null, `O local ${local.trim()} foi informado mais de uma vez.`]
    seen.add(key)
  }
  for (const [i, c] of form.carteiras.entries()) {
    if (!c.nome.trim() || (!c.sem_codigo && !c.codigo.trim())) return [null, `Na carteira ${i + 1}, informe ${c.nome.trim() ? "o código ou marque 'Sem código'" : 'o nome'}.`]
  }
  return [{
    solicitante: form.solicitante.trim(),
    tipo: form.tipo,
    carteiras: form.carteiras.map((c) => ({ codigo: c.sem_codigo ? '' : c.codigo.trim(), nome: c.nome.trim(), sem_codigo: c.sem_codigo, pas: String(c.pas).trim() === '' ? null : Number.parseInt(String(c.pas), 10) })),
    locais: form.locais.map((l) => l.trim()),
    versao: form.versao.trim(),
    versao_anterior: form.versao_anterior.trim(),
    descricao: form.descricao.trim(),
    link_planilha: form.link_planilha.trim(),
    sugestao_at: sugestao,
    service_status: form.service_status,
    sd_inicio: inicio,
    sd_fim: fim,
    chg_numero: form.chg_numero.trim().toUpperCase(),
    chg_status: form.chg_status,
    tecnico_id: /^\d+$/.test(form.tecnico_id) ? Number(form.tecnico_id) : null,
    manter_tecnico_historico: form.tecnico_id === 'historico',
    plans: form.plans.map((p) => ({ ...p, implantacao: p.implantacao.trim().toUpperCase(), backup: p.backup.trim().toUpperCase(), rollback: p.rollback.trim().toUpperCase() })),
  }, null]
}

/**
 * [Ajustes do CHG, D-CHG-41] Busca reversa do v5.2: o nome digitado igual ao
 * de uma carteira ativa do catálogo (sem diferença de maiúsculas e dos espaços
 * das pontas) dá o código; nome de mais de uma carteira ativa, nenhum.
 */
export function catalogCodeForName(carteiras, nome) {
  const key = String(nome || '').trim().toLocaleLowerCase('pt-BR')
  if (!key) return ''
  const hits = (carteiras || []).filter((c) => c.ativo && String(c.nome).trim().toLocaleLowerCase('pt-BR') === key)
  return hits.length === 1 ? hits[0].codigo : ''
}
