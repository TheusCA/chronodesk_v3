// [F9] Equipes do portal, a mesma lista do servidor (services/PortalTeams.php;
// desenho principal, 9.15; D-F9-1 e D-F9-2). Valores sem acento, rótulos com.
// - Equipes de técnico (painel, pausas, escala): N1, N2, Crítico e Híbrido.
// - Liderança: equipe de cadastro, fora do painel.
// - Grupo comum (Total da Visão operacional, times de rodízio): N1 e N2.
export type TeamOption = { value: string; label: string }

export const WORK_TEAMS: TeamOption[] = [
  { value: 'n1', label: 'N1' },
  { value: 'n2', label: 'N2' },
  { value: 'critico', label: 'Crítico' },
  { value: 'hibrido', label: 'Híbrido' },
]

export const LEADERSHIP_TEAM: TeamOption = { value: 'lideranca', label: 'Liderança' }

// Todas as equipes de cadastro, na ordem dos formulários.
export const ALL_TEAMS: TeamOption[] = [WORK_TEAMS[0], WORK_TEAMS[1], LEADERSHIP_TEAM, WORK_TEAMS[2], WORK_TEAMS[3]]

export const COMMON_GROUP = ['n1', 'n2']

const LABELS: Record<string, string> = Object.fromEntries(ALL_TEAMS.map((team) => [team.value, team.label]))

export function teamLabel(team: unknown): string {
  const value = String(team ?? '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
  return LABELS[value] ?? String(team ?? '').toUpperCase()
}

// Rótulo curto das listas e tabelas: o de antes em caixa alta (N1, N2,
// LIDERANCA) e o rótulo da lista única para Crítico e Híbrido.
export function teamShort(team: unknown): string {
  const value = String(team ?? '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
  return value === 'critico' || value === 'hibrido' ? LABELS[value] : String(team ?? '').toUpperCase()
}

export function isCriticalTeam(team: unknown): boolean {
  return String(team ?? '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '') === 'critico'
}
