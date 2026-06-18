import { Icon } from './Icon'

export function SectionHeader({ eyebrow, title, description, action = null, meta = null }) {
  return (
    <section className="section-header">
      <div className="min-w-0">
        {eyebrow && <p className="section-eyebrow">{eyebrow}</p>}
        <h2 className="section-title">{title}</h2>
        {description && <p className="section-description">{description}</p>}
      </div>
      {(action || meta) && (
        <div className="flex flex-wrap items-center justify-start gap-2 sm:justify-end">
          {meta}
          {action}
        </div>
      )}
    </section>
  )
}

export function MetricCard({ label, value, detail, tone = 'info', icon = 'dashboard' }) {
  return (
    <article className={`metric-card metric-card-${tone}`}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="metric-label">{label}</p>
          <p className="metric-value">{value}</p>
        </div>
        <span className="metric-icon" aria-hidden="true">
          <Icon className="h-4 w-4" name={icon} />
        </span>
      </div>
      {detail && <p className="metric-detail">{detail}</p>}
    </article>
  )
}

export function UserAvatar({ name, className = '' }) {
  const initials = String(name || 'SDK')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase()

  return <span className={`user-avatar ${className}`} aria-hidden="true">{initials || 'SDK'}</span>
}

export function StatusDot({ tone = 'info', label }) {
  return (
    <span className={`status-dot status-dot-${tone}`}>
      <span aria-hidden="true" />
      {label}
    </span>
  )
}
