// EXAMPLE ONLY. Not imported by production runtime.
// Requires future dependency: @tanstack/react-table.

import type { ColumnDef } from '@tanstack/react-table'

type CriticalIncidentRow = {
  id: number
  incident_number?: string
  ticket_number?: string
  room_date?: string
  severity: 'low' | 'medium' | 'high' | 'critical'
  status: 'open' | 'in_progress' | 'war_room' | 'mitigated' | 'resolved' | 'cancelled'
  sdk_responsible_name?: string
}

export const criticalIncidentColumns: ColumnDef<CriticalIncidentRow>[] = [
  {
    accessorFn: (row) => row.incident_number || row.ticket_number || '',
    id: 'incident',
    header: 'INCIDENTE',
  },
  {
    accessorKey: 'room_date',
    header: 'Data da sala',
  },
  {
    accessorKey: 'severity',
    header: 'Criticidade',
  },
  {
    accessorKey: 'status',
    header: 'Status',
  },
  {
    accessorKey: 'sdk_responsible_name',
    header: 'Tecnico SDK',
  },
]
