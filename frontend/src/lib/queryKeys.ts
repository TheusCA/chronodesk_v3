import type { QueryParams } from './operational'

export const queryKeys = {
  session: ['session'] as const,
  metrics: ['metrics'] as const,
  reports: (filters: QueryParams = {}) => ['reports', filters] as const,
}
