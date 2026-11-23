import { useCallback, useEffect, useRef, useState } from 'react'
import { ConfirmDiscardDialog } from '../../components/ui/ConfirmDiscardDialog'
import { Drawer } from '../../components/ui/Drawer'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { STAGE_TITLE, carteiraLabel } from '../../lib/chg'
import { ChgCardBody } from './ChgCard'
import { pendingFields, resetPendingFields } from './chgPanelModel'

// [Ajustes do CHG, D-CHG-35] Painel "Abrir": o card inteiro (planos,
// endpoints e início, rollback, tarefas) num Drawer largo, como o "Nova
// atualização". Os campos gravam ao sair (row_version; 409 recarrega), como no
// card de antes. Sujo = algum campo digitado e ainda não gravado (o valor
// difere do carregado): fechar (Esc, clique fora, Fechar, Editar, Duplicar) e
// trocar de rota perguntam antes de descartar. Descartar devolve o valor
// carregado ao campo antes de fechar, para nada ser gravado na saída.

export function ChgPanel({ card, canManage, technicians, onAct, onCopy, onCopyTable, onConfirm, onEdit, onDuplicate, onInvalid, onClose, returnFocusRef = null }) {
  const bodyRef = useRef(null)
  const [dirty, setDirty] = useState(false)
  const recompute = useCallback(() => setDirty(pendingFields(bodyRef.current).length > 0), [])
  // Gravado (versão nova), os campos voltam a ser os carregados.
  useEffect(() => { recompute() }, [card.row_version, recompute])
  const { requestLeave, dialogProps } = useUnsavedChangesGuard(dirty)
  const leave = (action) => requestLeave(() => {
    resetPendingFields(bodyRef.current)
    action()
  })
  const first = card.carteiras[0] ? carteiraLabel(card.carteiras[0]) : 'Carteira não informada'
  const editable = canManage
  return (
    <>
      <Drawer
        description={`${STAGE_TITLE[card.view.stage]} · ${card.chg_numero || 'Sem CHG'}${card.carteiras.length > 1 ? ` · ${card.carteiras.length} carteiras` : ''}`}
        footer={(
          <>
            <button className="btn-secondary chg-btn" data-chg-leave="" data-chg-panel-close="" onClick={() => leave(onClose)} type="button">Fechar</button>
            {editable && <button className="btn-secondary chg-btn" onClick={() => onConfirm({ title: 'Arquivar atualização?', description: 'O card sai do quadro (fica guardado no banco). A ação é registrada.', confirmLabel: 'Arquivar', run: () => onAct(card, 'archive') })} type="button">Arquivar</button>}
            {editable && <button className="btn-secondary chg-btn" data-chg-leave="" onClick={() => leave(() => { onClose(); onDuplicate(card) })} type="button">Duplicar</button>}
            {editable && <button className="btn-primary chg-btn" data-chg-edit={card.id} data-chg-leave="" onClick={() => leave(() => { onClose(); onEdit(card) })} type="button">Editar</button>}
          </>
        )}
        onClose={() => leave(onClose)}
        returnFocusRef={returnFocusRef}
        size="max-w-4xl"
        title={first}
      >
        <div className="chg-panel-body" data-chg-panel={card.id} onBlur={recompute} onInput={recompute} ref={bodyRef}>
          <ChgCardBody card={card} canManage={canManage} onAct={onAct} onConfirm={onConfirm} onCopy={onCopy} onCopyTable={onCopyTable} onInvalid={onInvalid} technicians={technicians} />
        </div>
      </Drawer>
      <ConfirmDiscardDialog {...dialogProps} />
    </>
  )
}
