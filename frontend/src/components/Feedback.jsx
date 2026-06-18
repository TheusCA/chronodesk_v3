import { Icon } from './ui/Icon'

export function Feedback({ feedback }) {
  if (!feedback) return null
  const error = feedback.type === 'error'
  const colors = error
    ? 'border-red-500/50 bg-red-500/10 text-red-200'
    : 'border-emerald-500/50 bg-emerald-500/10 text-emerald-200'

  return (
    <div className={`fixed bottom-4 right-4 z-50 flex max-w-md items-start gap-3 rounded-card border px-4 py-3 text-sm shadow-2xl backdrop-blur ${colors}`} role="status">
      <Icon className="mt-0.5 h-4 w-4 shrink-0" name={error ? 'alert' : 'shield'} />
      <span>{feedback.message}</span>
    </div>
  )
}
