// EXAMPLE ONLY. Not imported by production runtime.
// Requires future dependency: zod.

type ScheduleRuleType =
  | 'even_days'
  | 'odd_days'
  | 'always_onsite'
  | 'always_remote'
  | 'fixed_weekdays'
  | 'undefined'

type ScheduleRuleInput = {
  employee_id: number | string
  rule_type: ScheduleRuleType
  effective_from: string
}

// Future Zod shape:
// z.object({
//   employee_id: z.coerce.number().int().positive(),
//   rule_type: z.enum(['even_days', 'odd_days', 'always_onsite', 'always_remote', 'fixed_weekdays', 'undefined']),
//   effective_from: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
// })

export function toScheduleRulePayloadExample(input: ScheduleRuleInput) {
  return {
    action: 'rule' as const,
    employee_id: Number(input.employee_id),
    rule_type: input.rule_type,
    effective_from: input.effective_from,
  }
}
