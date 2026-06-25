import { z } from 'zod'

export const reportFiltersSchema = z.object({
  competency: z.string().min(1, 'Informe a competencia.').regex(/^\d{4}-\d{2}$/, 'Competencia invalida.'),
  team: z.enum(['', 'n1', 'n2']).default(''),
  employee_id: z.string().default(''),
})

export type ReportFiltersFormValues = z.infer<typeof reportFiltersSchema>
