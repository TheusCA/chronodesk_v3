// Perfis que passam onde o gestor passa (Lote 5a). Espelha
// PORTAL_MANAGER_ROLES de security.php; a decisão de acesso é sempre do servidor.
export const MANAGER_ROLES = ['admin', 'lideranca', 'gestor'] as const

export function isManagerRole(role: unknown): boolean {
  return typeof role === 'string' && (MANAGER_ROLES as readonly string[]).includes(role)
}

// Lote 5c: a tela pergunta pela permissão que o servidor devolve em
// session.php, não pelo nome do perfil.
export function hasPermission(session: { permissions?: unknown } | null | undefined, permission: string): boolean {
  return Array.isArray(session?.permissions) && session.permissions.includes(permission)
}
