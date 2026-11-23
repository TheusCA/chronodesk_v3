// [Lote NC revisto, D-NC-14 e D-NC-15] Prévia da planilha de horas extras do
// RH: o que vai, o que fica fora, o total geral e quem está sem NC. A planilha
// só sai sem nenhum lançamento aprovado sem NC (o servidor recusa também).

export type RhPreview = {
  arquivo: string
  aprovados: number
  pendentes_fora: number
  outros_fora: number
  total_minutos: number
  sem_nc: { lancamentos: number; pessoas: Array<{ employee_id: number; employee_name: string }> }
}

export function rhCanDownload(preview: RhPreview | null | undefined): boolean {
  return Boolean(preview) && preview!.sem_nc.lancamentos === 0
}

/** Total geral em horas e minutos (o mesmo [h]:mm da planilha, sem os segundos). */
export function rhTotalLabel(minutes: number): string {
  const total = Math.max(0, Math.round(minutes))
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`
}
