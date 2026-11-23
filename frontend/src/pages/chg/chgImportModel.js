// [9.17.7] Modelo da importação do Controle v5 (sem componentes): os rótulos
// das contagens da prévia e a leitura do arquivo escolhido.

export const IMPORT_MAX_BYTES = 2 * 1024 * 1024
export const IMPORT_LABELS = {
  novos: 'Cards novos',
  atualizados: 'Cards atualizados (o arquivo é mais novo)',
  iguais: 'Cards iguais (nada muda)',
  mais_antigos_que_o_portal: 'Ignorados: mais antigos que os do portal',
  ignorados_em_deleted: 'Ignorados: estão em "deleted"',
  em_deleted_e_no_portal: 'Em "deleted" e já no portal (arquive pela tela)',
  repetidos_no_arquivo: 'Repetidos no arquivo',
  invalidos: 'Inválidos (fora do esquema)',
  arquivados_no_portal: 'Arquivados no portal (não atualizados)',
  tecnicos_sem_correspondencia: 'Técnicos sem correspondência (ficam no histórico)',
  links_completados: 'Links completados com https://',
  links_descartados: 'Links descartados (não são http ou https)',
  fim_antes_do_inicio_limpo: 'Fim do Service Desk antes do início (limpo)',
  sem_solicitante: 'Sem solicitante',
  sem_carteira: 'Sem carteira',
  sem_local: 'Sem local',
  pas_totais_na_primeira_carteira: 'Total de PAs na primeira carteira',
  etapa_manual_sem_base: 'Etapa manual sem a base (usada a automática)',
  rollbacks_inativos_ignorados: 'Rollbacks inativos ignorados',
  carteiras_novas: 'Carteiras novas no catálogo',
  carteiras_existentes: 'Carteiras já no catálogo',
  carteiras_invalidas: 'Carteiras inválidas',
  locais_novos: 'Locais novos no catálogo',
  locais_existentes: 'Locais já no catálogo',
  locais_invalidos: 'Locais inválidos',
}

/** Lê o arquivo escolhido como JSON (null sem arquivo); erro com mensagem curta. */
export async function readJsonFile(file) {
  if (!file) return null
  if (file.size > IMPORT_MAX_BYTES) throw new Error('Arquivo acima de 2 MB.')
  try {
    return JSON.parse(await file.text())
  } catch {
    throw new Error('O arquivo escolhido não é um JSON válido.')
  }
}

/** Linhas da prévia: as contagens acima de zero (novos e atualizados sempre) e os campos descartados. */
export function summaryRows(result) {
  const counts = Object.entries(result.contagens || {}).filter(([key, value]) => value > 0 || ['novos', 'atualizados'].includes(key)).map(([key, value]) => [IMPORT_LABELS[key] ?? key, value])
  const fields = Object.entries(result.campos_descartados || {}).map(([field, value]) => [`Campo descartado: ${field}`, value])
  return [...counts, ...fields]
}

// [Ajustes do CHG, D-CHG-39 e D-CHG-40] Prévia da importação do catálogo de
// carteiras pela planilha: os grupos, as contagens e quando dá para confirmar.
export const CATALOG_GROUPS = [
  { key: 'novas', label: 'Novas' },
  { key: 'renomeadas', label: 'Renomeadas' },
  { key: 'reativadas', label: 'Reativadas' },
  { key: 'sem_mudanca', label: 'Sem mudança' },
  { key: 'sairam', label: 'Saíram (ficam inativas)' },
  { key: 'recusadas', label: 'Recusadas' },
]

export function catalogSummaryRows(result) {
  return [['Linhas lidas', result?.resumo?.linhas ?? 0], ...CATALOG_GROUPS.map((g) => [g.label, result?.resumo?.[g.key] ?? 0])]
}

/** Confirmar só com alguma carteira válida e alguma mudança. */
export function catalogCanConfirm(preview) {
  return Boolean(preview?.pode_confirmar) && Number(preview?.mudancas) > 0
}

export function catalogCounts(carteiras) {
  const list = carteiras || []
  return { ativas: list.filter((c) => c.ativo).length, inativas: list.filter((c) => !c.ativo).length }
}
