export type Permission =
  | 'pausas.use'
  | 'metricas.read'
  | 'operacao.approve'
  | 'relatorios.read'
  | 'configuracoes.manage'
  | 'integracoes.manage'
  | 'feriados.manage'

export type NavigationSection = {
  section: string
}

export type NavigationRoute = {
  path: string
  label: string
  icon: string
  permission?: Permission
  // [D-ESC-16] Basta uma das permissões (Configurações: o admin, ou a Liderança só nos feriados).
  anyPermission?: Permission[]
  // [9.17] Módulo ligado por interruptor do servidor (session.modulos).
  module?: 'chg'
}

export type NavigationItem = NavigationSection | NavigationRoute
export type PageMetadata = Record<string, [title: string, description: string]>

export const navigation: NavigationItem[] = [
  { section: 'Centro operacional' },
  { path: '/dashboard', label: 'Visão operacional', icon: 'dashboard' },
  { path: '/calendario', label: 'Calendário', icon: 'calendar' },
  { path: '/tecnicos', label: 'Técnicos', icon: 'users' },
  { path: '/mapa-pa', label: 'Mapa de PA', icon: 'building' },
  { section: 'Pausas e aprovações' },
  { path: '/pausas', label: 'Pausas', icon: 'pause', permission: 'pausas.use' },
  { path: '/metricas', label: 'Métricas', icon: 'chart', permission: 'metricas.read' },
  { path: '/admin', label: 'Aprovações e admin', icon: 'shield', permission: 'operacao.approve' },
  { section: 'Escalas' },
  { path: '/escala-turnos', label: 'Escalas de Sábado', icon: 'clock' },
  { path: '/escala-presencial', label: 'Escala presencial', icon: 'building' },
  // [D-ESC-1] Escala do Crítico em grade: todos os perfis veem.
  { path: '/escala-critico', label: 'Escala do Crítico', icon: 'calendar' },
  // [F10-5] Escala do mês: todos veem o publicado; publicar com escala_mensal.publish.
  { path: '/escala-mes', label: 'Escala do mês', icon: 'calendar' },
  { path: '/ausencias', label: 'Ausências', icon: 'userMinus' },
  { path: '/plantonistas', label: 'Plantonistas', icon: 'phone' },
  { path: '/sobreavisos', label: 'Sobreavisos', icon: 'radio' },
  { section: 'Fluxos operacionais' },
  { path: '/horas-extras', label: 'Horas extras', icon: 'timer' },
  { path: '/correcao-ponto', label: 'Correção de ponto', icon: 'edit' },
  { path: '/avisos', label: 'Avisos', icon: 'bell' },
  { path: '/chamados-criticos', label: 'Chamados críticos', icon: 'alert' },
  { path: '/chg', label: 'Controle de CHG', icon: 'file', module: 'chg' },
  { section: 'Conhecimento e plataforma' },
  { path: '/documentacao', label: 'Documentação', icon: 'book' },
  { path: '/relatorios', label: 'Relatórios', icon: 'file', permission: 'relatorios.read' },
  { path: '/configuracoes', label: 'Configurações', icon: 'settings', anyPermission: ['configuracoes.manage', 'feriados.manage'] },
  { path: '/integracoes', label: 'Integrações', icon: 'plug', permission: 'integracoes.manage' },
]

export const pageMetadata: PageMetadata = {
  '/dashboard': ['Visão operacional', 'Acompanhe o estado do portal e da equipe.'],
  '/pausas': ['Pausas', 'Controle de pausas e disponibilidade em tempo real.'],
  '/admin': ['Administração', 'Aprovações, funcionários, usuários e segurança.'],
  '/metricas': ['Métricas', 'Indicadores e histórico de pausas.'],
  '/calendario': ['Calendário', 'Eventos, escalas e janelas operacionais.'],
  '/tecnicos': ['Técnicos', 'Diretório operacional da equipe.'],
  '/escala-turnos': ['Escalas de Sábado', 'Publicações da escala de sábado disponíveis para consulta da equipe.'],
  '/escala-presencial': ['Escala presencial', 'Presença por data, equipe e colaborador.'],
  '/escala-critico': ['Escala do Crítico', 'Papéis, ausências e feriados do Crítico, semana a semana.'],
  '/escala-mes': ['Escala do mês', 'Presencial, home e PA de cada dia útil do mês publicado, com o resumo.'],
  '/ausencias': ['Ausências', 'Folgas, férias, ausências justificadas e compromissos.'],
  '/horas-extras': ['Horas extras', 'Solicitações e aprovações de horas adicionais.'],
  '/correcao-ponto': ['Correção de ponto', 'Solicitações de ajuste de marcação.'],
  '/avisos': ['Avisos', 'Comunicados operacionais e de segurança.'],
  '/plantonistas': ['Plantonistas', 'Contatos e horários dos plantões ativos.'],
  '/sobreavisos': ['Sobreavisos', 'Cobertura de sobreaviso ativa e planejada.'],
  '/mapa-pa': ['Mapa de PA', 'Alocação visual de posições físicas por data.'],
  '/chamados-criticos': ['Chamados críticos', 'War rooms, impacto, mitigação e resolução de crises.'],
  '/chg': ['Controle de CHG', 'Atualizações por CHG: comitê, preparação, execução, validação e encerramento.'],
  '/documentacao': ['Documentação', 'WIs, IOPs, runbooks e checklists.'],
  '/relatorios': ['Relatórios', 'Relatórios operacionais e auditoria.'],
  '/configuracoes': ['Configurações', 'Preferências e informações de segurança.'],
  '/integracoes': ['Integrações', 'Estado das conexões com plataformas externas.'],
}

// Rota permitida para o conjunto de permissões da sessão (permission e anyPermission).
export function routeAllowed(item: Pick<NavigationRoute, 'permission' | 'anyPermission'> | undefined, permissions: Set<string>): boolean {
  if (!item) return true
  if (item.permission && !permissions.has(item.permission)) return false
  if (item.anyPermission && !item.anyPermission.some((permission) => permissions.has(permission))) return false
  return true
}
