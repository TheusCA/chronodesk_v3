// [Lote NC] NC: a matrícula do cadastro Senior (docs/DESENHO_NC_E_ICONE.md).
// A lista de funcionários só traz o campo para Liderança e admin; a hora extra
// e a correção de ponto leem o NC do cadastro pelo servidor (só leitura).

export const NC_REQUIRED_MESSAGE = 'Sua matrícula (NC) não está no cadastro. Peça à Liderança para incluir.'

/** Cadastro da lista sem NC (só quando a lista trouxe o campo). */
export function employeeWithoutNc(item: { nc?: string | null }): boolean {
  return 'nc' in item && !item.nc
}

export type WorkflowNc = { employee_id: number; nc: string | null; nc_required: boolean }

/**
 * Estado do NC no formulário de hora extra e de correção de ponto: sem NC,
 * avisa; sem NC e com NC_OBRIGATORIO ligada, não envia (o servidor recusa).
 */
export function workflowNcState(info: WorkflowNc | null | undefined): { missing: boolean; blocked: boolean } {
  const missing = Boolean(info) && !info?.nc
  return { missing, blocked: missing && info?.nc_required === true }
}

// [Lote NC revisto, D-NC-11 e D-NC-12] Importação de NCs com prévia.
export type NcImportSummary = {
  linhas: number; entra: number; muda: number; igual: number; nao_casou: number; nc_invalido: number; nc_repetido: number
}

/** Rótulos e contagens da prévia, na ordem da tela. */
export function ncImportSummary(resumo: NcImportSummary | null | undefined): Array<[string, number]> {
  if (!resumo) return []
  return [
    ['Entram', resumo.entra],
    ['Mudam', resumo.muda],
    ['Iguais', resumo.igual],
    ['Não casaram', resumo.nao_casou],
    ['NC inválido', resumo.nc_invalido],
    ['NC repetido', resumo.nc_repetido],
  ]
}

/** Confirmar só com prévia do mesmo arquivo e algo a gravar (entra ou muda). */
export function ncImportCanConfirm(preview: { resumo?: NcImportSummary } | null | undefined): boolean {
  return Boolean(preview?.resumo) && (preview!.resumo!.entra + preview!.resumo!.muda) > 0
}
