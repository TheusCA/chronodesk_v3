// Perfis que passam onde o gestor passa (Lote 5a). Espelha
// PORTAL_MANAGER_ROLES de security.php; a decisão de acesso é sempre do servidor.
export const MANAGER_ROLES = ['admin', 'lideranca', 'gestor'] as const

export function isManagerRole(role: unknown): boolean {
  return typeof role === 'string' && (MANAGER_ROLES as readonly string[]).includes(role)
}
