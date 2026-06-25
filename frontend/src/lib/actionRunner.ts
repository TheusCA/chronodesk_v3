import { ACTION_FEEDBACK } from './actionFeedback'

type NotifyType = 'success' | 'error' | 'warning' | 'info' | string
type NotifyFunction = (message: string, type?: NotifyType) => void
type RefreshFunction = () => Promise<unknown> | unknown

export type ActionRunnerOptions<T> = {
  action: () => Promise<T>
  refresh?: RefreshFunction | RefreshFunction[]
  notify: NotifyFunction
  successMessage?: string | null
  onSuccess?: (result: T) => Promise<unknown> | unknown
  onError?: (error: unknown) => Promise<unknown> | unknown
}

function errorMessage(error: unknown): string {
  if (error instanceof Error && error.message) return error.message
  return 'Não foi possível concluir a ação.'
}

export async function runAction<T>({
  action,
  refresh,
  notify,
  successMessage = ACTION_FEEDBACK.changesSaved,
  onSuccess,
  onError,
}: ActionRunnerOptions<T>): Promise<T | null> {
  try {
    const result = await action()
    const refreshers = Array.isArray(refresh) ? refresh : refresh ? [refresh] : []

    for (const refreshFn of refreshers) {
      await refreshFn()
    }

    if (onSuccess) {
      await onSuccess(result)
    }

    if (successMessage !== null) {
      const fallback = typeof result === 'object' && result !== null
        ? (result as { mensagem?: string; message?: string }).mensagem
          || (result as { mensagem?: string; message?: string }).message
        : ''
      notify(successMessage || fallback || ACTION_FEEDBACK.changesSaved)
    }

    return result
  } catch (error) {
    if (onError) {
      await onError(error)
    }
    notify(errorMessage(error), 'error')
    return null
  }
}
