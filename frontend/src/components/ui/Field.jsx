import { Children, cloneElement, useId } from 'react'
import { Icon } from './Icon'

// [R2] Campo de formulário: rótulo acima, ajuda e erro abaixo, os dois ligados
// ao controle por aria-describedby; com erro, aria-invalid. O erro tem ícone e
// texto (nunca só a borda vermelha). children: um único controle (input,
// select ou textarea), que pode vir com o register do React Hook Form (a ref é
// preservada pelo cloneElement).
export function Field({ label, help = null, error = null, className = '', children }) {
  const id = useId()
  const control = Children.only(children)
  const controlId = control.props.id ?? `${id}-campo`
  const helpId = help ? `${id}-ajuda` : null
  const errorId = error ? `${id}-erro` : null
  const describedBy = [control.props['aria-describedby'], helpId, errorId].filter(Boolean).join(' ') || undefined
  return (
    <div className={`field-group ${className}`.trim()}>
      <label className="label" htmlFor={controlId}>{label}</label>
      {cloneElement(control, {
        id: controlId,
        className: control.props.className ?? 'field',
        'aria-describedby': describedBy,
        'aria-invalid': error ? true : control.props['aria-invalid'],
      })}
      {help && <p className="field-help" id={helpId}>{help}</p>}
      {error && (
        <p className="field-error" id={errorId}>
          <Icon className="mt-0.5 h-3.5 w-3.5 shrink-0" name="alert" />
          <span>{error}</span>
        </p>
      )}
    </div>
  )
}
