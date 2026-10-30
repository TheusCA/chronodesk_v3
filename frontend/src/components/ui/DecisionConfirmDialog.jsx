import { DECISION_WARNING, decisionDialogText } from '../../lib/decisionConfirm'
import { ConfirmActionDialog } from './ConfirmActionDialog'

// [17g] Confirmação de Aprovar e Rejeitar. Ligada ao useDecisionConfirm.
export function DecisionConfirmDialog({ request, busy, onConfirm, onCancel, returnFocusRef }) {
  if (!request) return null
  const { title, employee, detail, confirmLabel, busyLabel, confirmClassName } = decisionDialogText(request.kind, request.item, request.decision)
  return (
    <ConfirmActionDialog
      busy={busy}
      busyLabel={busyLabel}
      confirmClassName={confirmClassName}
      confirmLabel={confirmLabel}
      description={(
        <>
          <span className="block font-semibold text-white">{employee}</span>
          <span className="block">{detail}</span>
          <span className="mt-2 block">{DECISION_WARNING}</span>
        </>
      )}
      onCancel={onCancel}
      onConfirm={onConfirm}
      returnFocusRef={returnFocusRef}
      title={title}
    />
  )
}
