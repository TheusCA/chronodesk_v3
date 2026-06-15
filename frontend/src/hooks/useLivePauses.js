import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api } from '../lib/api'
import { parseDate } from '../lib/format'

const EMPTY_STATUS = { n1: [], n2: [], server_now: null }

export function useLivePauses({ enabled = true, intervalMs = 15000 } = {}) {
  const [status, setStatus] = useState(EMPTY_STATUS)
  const [connection, setConnection] = useState('connecting')
  const [tick, setTick] = useState(Date.now())
  const serverOffsetRef = useRef(0)
  const mountedRef = useRef(true)

  const refresh = useCallback(async () => {
    try {
      const response = await api('status.php')
      if (!mountedRef.current) return null
      const serverTime = parseDate(response.server_now).getTime()
      if (Number.isFinite(serverTime)) serverOffsetRef.current = serverTime - Date.now()
      setStatus(response)
      setConnection('online')
      return response
    } catch (error) {
      if (mountedRef.current) setConnection('stale')
      throw error
    }
  }, [])

  useEffect(() => {
    mountedRef.current = true
    if (!enabled) return () => { mountedRef.current = false }
    refresh().catch(() => {})
    const polling = window.setInterval(() => refresh().catch(() => {}), intervalMs)
    const timer = window.setInterval(() => setTick(Date.now()), 1000)
    return () => {
      mountedRef.current = false
      window.clearInterval(polling)
      window.clearInterval(timer)
    }
  }, [enabled, intervalMs, refresh])

  const now = tick + serverOffsetRef.current
  const employees = useMemo(
    () => [...(status.n1 || []), ...(status.n2 || [])],
    [status],
  )
  const elapsedFor = useCallback((item) => {
    if (!item?.em_pausa) return Number(item?.elapsed_seconds || item?.tempo_pausa || 0)
    const startedAt = parseDate(item.inicio_pausa).getTime()
    if (!Number.isFinite(startedAt)) return Number(item.elapsed_seconds || item.tempo_pausa || 0)
    return Math.max(0, Math.floor((now - startedAt) / 1000))
  }, [now])

  return { status, employees, connection, refresh, elapsedFor, serverNow: now }
}
