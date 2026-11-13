// [R1, D-R0-2 e D-R1-1] Tema claro ou escuro. Sem escolha, vale a preferência
// do sistema (o CSS lê prefers-color-scheme sozinho). O botão do topo grava a
// escolha em data-theme na raiz e na sessão da aba (sessionStorage): vale até
// fechar a aba, não vai para o servidor e não fica no navegador depois. Guardar
// na conta pede API e fica para depois, com desenho próprio.

export type Theme = 'light' | 'dark'

const STORAGE_KEY = 'chronodesk.tema'

function isTheme(value: unknown): value is Theme {
  return value === 'light' || value === 'dark'
}

// Escolha da sessão; sem escolha, ou com o armazenamento bloqueado, null.
export function storedTheme(): Theme | null {
  try {
    const value = window.sessionStorage.getItem(STORAGE_KEY)
    return isTheme(value) ? value : null
  } catch {
    return null
  }
}

export function systemTheme(): Theme {
  return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

// Tema em uso agora: o escolhido na raiz ou, sem escolha, o do sistema.
export function activeTheme(root: HTMLElement = document.documentElement): Theme {
  const chosen = root.dataset.theme
  return isTheme(chosen) ? chosen : systemTheme()
}

export function applyTheme(theme: Theme, root: HTMLElement = document.documentElement): void {
  root.dataset.theme = theme
}

export function chooseTheme(theme: Theme): void {
  applyTheme(theme)
  try {
    window.sessionStorage.setItem(STORAGE_KEY, theme)
  } catch {
    // Sem armazenamento (aba privada ou bloqueio), o tema vale até recarregar.
  }
}

// Na abertura, antes do primeiro desenho do React: só a escolha da sessão.
export function restoreTheme(): void {
  const theme = storedTheme()
  if (theme !== null) applyTheme(theme)
}

export function oppositeTheme(theme: Theme): Theme {
  return theme === 'dark' ? 'light' : 'dark'
}
