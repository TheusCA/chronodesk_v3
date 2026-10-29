import { useCallback, useEffect, useState } from 'react'
import { guardNavigation } from './navigationGuard'

function appBasePath() {
  return '/app'
}

function currentLocation() {
  const base = appBasePath()
  const route = window.location.pathname.slice(base.length) || '/'
  return { path: route === '/' ? '/dashboard' : route.replace(/\/+$/, ''), search: window.location.search }
}

// [17f] Endereço da última navegação aceita: voltar ou avançar com um
// formulário sujo devolve a barra para ele até a pessoa decidir.
let committedHref = typeof window === 'undefined' ? '' : window.location.href

export function createPopStateHandler(setLocation) {
  return () => {
    const target = window.location.href
    const proceeded = guardNavigation(() => {
      if (window.location.href !== target) window.history.pushState({}, '', target)
      committedHref = target
      setLocation(currentLocation())
    })
    if (!proceeded) window.history.pushState({}, '', committedHref)
  }
}

export function useRouter() {
  const [location, setLocation] = useState(currentLocation)

  useEffect(() => {
    const onPopState = createPopStateHandler(setLocation)
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [])

  const navigate = useCallback((target, { replace = false } = {}) => {
    guardNavigation(() => {
      const [path, search = ''] = target.split('?')
      const nextUrl = `${appBasePath()}${path === '/' ? '/dashboard' : path}${search ? `?${search}` : ''}`
      window.history[replace ? 'replaceState' : 'pushState']({}, '', nextUrl)
      committedHref = window.location.href
      setLocation(currentLocation())
    })
  }, [])

  return { ...location, navigate }
}
