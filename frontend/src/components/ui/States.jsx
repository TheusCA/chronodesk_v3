import { Icon } from './Icon'

// [R2] Estados de carregando, vazio e erro.
// LoadingState: o esqueleto tem a forma do que vai chegar (shape: cards, table
// ou list), com role="status" e o rótulo para o leitor de tela; a classe
// .skeleton marca cada bloco (o roteiro de navegador espera ela sumir).
const SKELETONS = {
  cards: { wrapper: 'grid gap-4 md:grid-cols-3', blocks: ['h-28', 'h-28', 'h-28'] },
  table: { wrapper: 'space-y-2', blocks: ['h-10', 'h-11', 'h-11', 'h-11', 'h-11'] },
  list: { wrapper: 'space-y-3', blocks: ['h-16', 'h-16', 'h-16', 'h-16'] },
}

export function LoadingState({ label = 'Carregando dados...', shape = 'cards' }) {
  const skeleton = SKELETONS[shape] ?? SKELETONS.cards
  return (
    <div className="space-y-4" aria-label={label} aria-live="polite" data-loading-shape={shape in SKELETONS ? shape : 'cards'} role="status">
      <p className="text-sm text-muted">{label}</p>
      <div aria-hidden="true" className={skeleton.wrapper}>
        {skeleton.blocks.map((height, index) => <div className={`skeleton ${height}`} key={index} />)}
      </div>
    </div>
  )
}

// EmptyState: diz o que falta e, quando há, o próximo passo (action).
export function EmptyState({ title = 'Nenhum dado disponível', description = 'Os registros aparecerão aqui quando forem cadastrados.', action = null }) {
  return (
    <div className="break-words rounded-card border border-dashed border-line-strong bg-surface px-6 py-10 text-center">
      <Icon className="mx-auto h-6 w-6 text-muted" name="file" />
      <h3 className="mt-3 font-semibold text-ink">{title}</h3>
      <p className="mx-auto mt-1 max-w-xl text-sm text-muted">{description}</p>
      {action && <div className="mt-5 flex justify-center">{action}</div>}
    </div>
  )
}

// ErrorState: anunciado como alerta, com o motivo e o "Tentar novamente".
export function ErrorState({ message, onRetry }) {
  return (
    <div className="break-words rounded-card border border-danger-line border-l-4 border-l-danger bg-surface p-5" role="alert">
      <div className="flex items-start gap-3">
        <Icon className="mt-0.5 h-5 w-5 shrink-0 text-danger" name="alert" />
        <div>
          <h3 className="font-semibold text-ink">Não foi possível carregar os dados</h3>
          <p className="mt-1 text-sm text-ink-2">{message}</p>
          {onRetry && <button type="button" className="btn-secondary mt-4" onClick={onRetry}>Tentar novamente</button>}
        </div>
      </div>
    </div>
  )
}
