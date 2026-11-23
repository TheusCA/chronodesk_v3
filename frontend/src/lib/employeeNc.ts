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
