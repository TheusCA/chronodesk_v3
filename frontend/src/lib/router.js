import { useCallback, useEffect, useState } from 'react'

function appBasePath() {
  return '/app'
}

function currentLocation() {
  const base = appBasePath()
  const route = window.location.pathname.slice(base.length) || '/'
  return { path: route === '/' ? '/dashboard' : route.replace(/\/+$/, ''), search: window.location.search }
}

export function useRouter() {
  const [location, setLocation] = useState(currentLocation)

  useEffect(() => {
    const onPopState = () => setLocation(currentLocation())
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [])

  const navigate = useCallback((target, { replace = false } = {}) => {
    const [path, search = ''] = target.split('?')
    const nextUrl = `${appBasePath()}${path === '/' ? '/dashboard' : path}${search ? `?${search}` : ''}`
    window.history[replace ? 'replaceState' : 'pushState']({}, '', nextUrl)
    setLocation(currentLocation())
  }, [])

  return { ...location, navigate }
}
