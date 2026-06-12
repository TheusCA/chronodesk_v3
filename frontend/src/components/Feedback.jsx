export function Feedback({ feedback }) {
  if (!feedback) return null
  const colors = feedback.type === 'error'
    ? 'border-red-500/50 bg-red-500/10 text-red-200'
    : 'border-green-500/50 bg-green-500/10 text-green-200'

  return (
    <div className={`fixed bottom-4 right-4 z-50 max-w-md rounded-card border px-4 py-3 shadow-2xl ${colors}`} role="status">
      {feedback.message}
    </div>
  )
}
