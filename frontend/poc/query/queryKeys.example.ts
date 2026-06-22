// EXAMPLE ONLY. Not imported by production runtime.

type FilterValue = string | number | boolean | null | undefined
type Filters = Record<string, FilterValue>

export const queryKeys = {
  session: () => ['session'] as const,
  dashboard: () => ['dashboard'] as const,
  livePauses: () => ['pauses', 'live'] as const,
  documents: (filters: Filters) => ['documents', filters] as const,
  shiftAttachments: (filters: Filters) => ['shiftAttachments', filters] as const,
  schedules: (filters: Filters) => ['schedules', filters] as const,
  paMap: (filters: Filters) => ['paMap', filters] as const,
  criticalIncidents: (filters: Filters) => ['criticalIncidents', filters] as const,
  criticalIncident: (id: number | string) => ['criticalIncident', id] as const,
  workflow: (kind: 'overtime' | 'timeCorrections', filters: Filters) => ['workflow', kind, filters] as const,
}
