import { Icon } from './Icon'

// [R2] Botão do portal (docs/design/DIRECAO_VISUAL.md, direção A). Desenha com
// as classes de hoje (.btn-primary, .btn-secondary, .btn-ghost, .btn-danger),
// que todas as telas já usam; quem precisa só da classe continua com ela.
// - variant: primary (a ação da tela, uma por vez), secondary, quiet ou danger;
// - size: md (40 px) ou sm (compacto, 32 px de 640 px para cima; no celular
//   continua com 40 px, o alvo mínimo de toque);
// - busy: desabilita e troca o rótulo por busyLabel, com aria-busy;
// - type padrão "button": nunca envia formulário sem querer.
const VARIANTS = { primary: 'btn-primary', secondary: 'btn-secondary', quiet: 'btn-ghost', danger: 'btn-danger' }

export function Button({ variant = 'secondary', size = 'md', type = 'button', busy = false, busyLabel = null, icon = null, disabled = false, className = '', children, ...props }) {
  const classes = [VARIANTS[variant] ?? VARIANTS.secondary, size === 'sm' ? 'btn-sm' : '', className].filter(Boolean).join(' ')
  return (
    <button {...props} aria-busy={busy || undefined} className={classes} disabled={disabled || busy} type={type}>
      {icon && <Icon className="h-4 w-4 shrink-0" name={icon} />}
      {busy && busyLabel ? busyLabel : children}
    </button>
  )
}
