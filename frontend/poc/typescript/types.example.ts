// EXAMPLE ONLY. Not imported by production runtime.

export type PortalRole = 'admin' | 'gestor' | 'tecnico' | 'somente_leitura' | null

export type ScheduleRuleType =
  | 'even_days'
  | 'odd_days'
  | 'always_onsite'
  | 'always_remote'
  | 'undefined'

export type WorkflowStatus = 'pending' | 'approved' | 'rejected' | 'synced' | 'sync_error'

export interface PortalSession {
  role: PortalRole
  permissions: string[]
  ci: {
    autenticado: boolean
    funcionario_id: number
    nome: string
    username: string
  }
  gestor: {
    autenticado: boolean
    admin: boolean
    username: string
    role: PortalRole
  }
}

export interface EmployeeOption {
  id: number
  name: string
  team: 'n1' | 'n2' | 'lideranca' | string
  ad_login?: string
}

export interface ScheduleRulePayload {
  action: 'rule'
  employee_id: number
  rule_type: ScheduleRuleType
  effective_from: string
}
