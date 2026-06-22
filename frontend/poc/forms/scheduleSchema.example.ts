// EXAMPLE ONLY. Not imported by production runtime.
// Requires future dependency: zod.

import { z } from 'zod'

export const scheduleRuleSchema = z.object({
  employee_id: z.coerce.number().int().positive(),
  rule_type: z.enum([
    'even_days',
    'odd_days',
    'always_onsite',
    'always_remote',
    'undefined',
  ]),
  effective_from: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
})

export function toScheduleRulePayload(input: unknown) {
  const parsed = scheduleRuleSchema.parse(input)
  return {
    action: 'rule' as const,
    employee_id: parsed.employee_id,
    rule_type: parsed.rule_type,
    effective_from: parsed.effective_from,
  }
}
