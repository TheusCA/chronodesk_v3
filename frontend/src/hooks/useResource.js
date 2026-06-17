import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../lib/api'

export function useResource(path, { enabled = true, initialData = null, intervalMs = 0, pauseWhenHidden = true } = {}) {
  const [data, setData] = useState(initialData)
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState(null)
  const loadedRef = useRef(initialData !== null)
  const requestRef = useRef(0)

  const refresh = useCallback(async () => {
    if (!enabled) return null
    const requestId = ++requestRef.current
    if (!loadedRef.current) setLoading(true)
    setError(null)
    try {
      const response = await api(path)
      if (requestRef.current !== requestId) return null
      loadedRef.current = true
      setData(response)
      return response
    } catch (requestError) {
      if (requestRef.current !== requestId) return null
      setError(requestError)
      return null
    } finally {
      if (requestRef.current === requestId) setLoading(false)
    }
  }, [enabled, path])

  useEffect(() => {
    if (!enabled) {
      requestRef.current += 1
      loadedRef.current = initialData !== null
      setData(initialData)
      setError(null)
      setLoading(false)
      return undefined
    }
    loadedRef.current = false
    setLoading(true)
    refresh()
    return () => {
      requestRef.current += 1
    }
  }, [enabled, initialData, path, refresh])

  useEffect(() => {
    if (!enabled || !intervalMs) return undefined
    const refreshIfVisible = () => {
      if (pauseWhenHidden && document.visibilityState === 'hidden') return
      refresh()
    }
    const polling = window.setInterval(refreshIfVisible, intervalMs)
    const handleVisibility = () => {
      if (document.visibilityState === 'visible') refresh()
    }
    if (pauseWhenHidden) document.addEventListener('visibilitychange', handleVisibility)
    return () => {
      window.clearInterval(polling)
      if (pauseWhenHidden) document.removeEventListener('visibilitychange', handleVisibility)
    }
  }, [enabled, intervalMs, pauseWhenHidden, refresh])

  return { data, loading, error, refresh, setData }
}
