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

export function FilterBar({ children, actions = null }) {
  return (
    <section className="filter-bar">
      <div className="filter-grid">{children}</div>
      {actions && <div className="filter-actions">{actions}</div>}
    </section>
  )
}

export function FormSection({ eyebrow, title, description, children, actions = null, className = '' }) {
  return (
    <section className={`form-section ${className}`}>
      <SectionHeader
        action={actions}
        description={description}
        eyebrow={eyebrow}
        title={title}
      />
      <div className="mt-5">{children}</div>
    </section>
  )
}

export function InlineAlert({ tone = 'info', title, children, className = '' }) {
  return (
    <div className={`inline-alert inline-alert-${tone} ${className}`}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" name={tone === 'danger' ? 'alert' : tone === 'warning' ? 'bell' : 'shield'} />
      <div>
        {title && <strong className="block text-sm">{title}</strong>}
        <div className="text-sm leading-6">{children}</div>
      </div>
    </div>
  )
}

export function DetailPill({ label, value, tone = 'neutral' }) {
  return (
    <span className={`detail-pill detail-pill-${tone}`}>
      <span>{label}</span>
      <strong>{value}</strong>
    </span>
  )
}

export function FileTypeBadge({ extension, label = null }) {
  const ext = String(extension || 'file').toUpperCase()
  const spreadsheet = ['CSV', 'XLS', 'XLSX'].includes(ext)
  const image = ['PNG', 'JPG', 'JPEG'].includes(ext)
  const tone = spreadsheet ? 'success' : image ? 'info' : ext === 'PDF' ? 'danger' : 'neutral'

  return <span className={`status-badge status-${tone}`}>{label || ext}</span>
}

export function OperationalLegend({ items }) {
  return (
    <section className="legend-panel" aria-label="Legenda operacional">
      {items.map((item) => (
        <div className="legend-item" key={item.label}>
          <span className={`legend-dot ${item.className || ''}`} aria-hidden="true" />
          <span>{item.label}</span>
        </div>
      ))}
    </section>
  )
}
