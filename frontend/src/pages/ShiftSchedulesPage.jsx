import { useEffect, useMemo, useRef, useState } from 'react'
import { Icon } from '../components/ui/Icon'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { useResource } from '../hooks/useResource'
import { apiUrl, postForm } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { localDate, queryString } from '../lib/operational'

const accepted = ['.png', '.jpg', '.jpeg', '.pdf', '.csv', '.xlsx']

function UploadShift({ limits, onClose, onUploaded }) {
  const inputRef = useRef(null)
  const [file, setFile] = useState(null)
  const [dragging, setDragging] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [metadata, setMetadata] = useState({
    title: '', reference_month: localDate().slice(0, 7), notes: '',
  })
  const previewUrl = useMemo(
    () => (file?.type.startsWith('image/') ? URL.createObjectURL(file) : ''),
    [file],
  )
  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl)
  }, [previewUrl])

  function choose(files) {
    const selected = files?.[0]
    setError('')
    if (!selected) return
    const lower = selected.name.toLowerCase()
    if (!accepted.some((extension) => lower.endsWith(extension))) {
      setError('Formatos suportados: PNG, JPG, PDF, CSV e XLSX. XLS legado deve ser convertido.')
      return
    }
    if (selected.size > Number(limits?.max_file_bytes || 10485760)) {
      setError('O arquivo excede o limite de 10 MB.')
      return
    }
    setFile(selected)
    if (!metadata.title) setMetadata((current) => ({ ...current, title: selected.name.replace(/\.[^.]+$/, '') }))
  }

  async function submit(event) {
    event.preventDefault()
    if (!file) return setError('Selecione um arquivo.')
    setLoading(true)
    setError('')
    try {
      const body = new FormData()
      body.append('attachment', file)
      Object.entries(metadata).forEach(([key, value]) => body.append(key, value))
      const result = await postForm('portal/shift_attachments.php', body)
      await onUploaded(result.mensagem)
      onClose()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/75 p-4 backdrop-blur-sm">
      <button className="fixed inset-0" aria-label="Fechar upload" onClick={onClose} type="button" />
      <form className="card relative mx-auto my-6 max-w-2xl space-y-5" onSubmit={submit}>
        <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-wider text-blue-400">Publicacao segura</p><h2 className="mt-2 text-xl font-bold text-white">Adicionar escala de turnos</h2></div><button className="text-sm text-slate-400" onClick={onClose} type="button">Cancelar</button></div>
        <button
          className={`drop-zone ${dragging ? 'drop-zone-active' : ''}`}
          onClick={() => inputRef.current?.click()}
          onDragEnter={(event) => { event.preventDefault(); setDragging(true) }}
          onDragLeave={(event) => { event.preventDefault(); setDragging(false) }}
          onDragOver={(event) => event.preventDefault()}
          onDrop={(event) => { event.preventDefault(); setDragging(false); choose(event.dataTransfer.files) }}
          type="button"
        >
          <Icon className="h-9 w-9 text-blue-400" name="upload" />
          <strong className="mt-3 text-sm text-slate-200">Clique ou arraste imagem, PDF ou planilha</strong>
          <span className="mt-1 text-xs text-slate-500">PNG, JPG, PDF, CSV ou XLSX, ate 10 MB</span>
        </button>
        <input ref={inputRef} accept={accepted.join(',')} className="hidden" onChange={(event) => choose(event.target.files)} type="file" />
        {file && (
          <div className="rounded-xl border border-white/10 bg-slate-950/40 p-4">
            {previewUrl && <img alt="Preview da escala" className="mb-3 max-h-72 w-full rounded-lg object-contain" src={previewUrl} />}
            <p className="truncate text-sm font-semibold text-slate-200">{file.name}</p>
            <p className="mt-1 text-xs text-slate-500">{Math.ceil(file.size / 1024)} KB</p>
          </div>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="label sm:col-span-2">Titulo<input className="field mt-2" maxLength="180" required value={metadata.title} onChange={(event) => setMetadata({ ...metadata, title: event.target.value })} /></label>
          <label className="label">Mes de referencia<input className="field mt-2" required type="month" value={metadata.reference_month} onChange={(event) => setMetadata({ ...metadata, reference_month: event.target.value })} /></label>
          <label className="label sm:col-span-2">Observacoes<textarea className="field mt-2 min-h-24" maxLength="2000" value={metadata.notes} onChange={(event) => setMetadata({ ...metadata, notes: event.target.value })} /></label>
        </div>
        {error && <p className="rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}
        <div className="flex justify-end gap-2"><button className="btn-secondary" onClick={onClose} type="button">Cancelar</button><button className="btn-primary" disabled={loading || !file} type="submit">{loading ? 'Enviando...' : 'Publicar escala'}</button></div>
      </form>
    </div>
  )
}

export function ShiftSchedulesPage({ notify }) {
  const [filters, setFilters] = useState({ month: '', extension: '', search: '' })
  const resource = useResource(`portal/shift_attachments.php${queryString(filters)}`)
  const [uploadOpen, setUploadOpen] = useState(false)
  const items = useMemo(() => resource.data?.items || [], [resource.data?.items])

  if (resource.loading) return <LoadingState label="Carregando escalas" />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  return (
    <div className="space-y-5">
      <section className="card flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div><p className="text-xs font-bold uppercase tracking-[0.18em] text-blue-400">Feed operacional</p><h2 className="mt-2 text-2xl font-black text-white">Escalas de turnos publicadas</h2><p className="mt-2 text-sm text-slate-400">Imagens e planilhas ficam em storage privado com download mediado.</p></div>
        {resource.data?.can_upload && <button className="btn-primary gap-2" onClick={() => setUploadOpen(true)} type="button"><Icon name="upload" /> Adicionar arquivo</button>}
      </section>
      <section className="card grid gap-3 sm:grid-cols-3">
        <input className="field" placeholder="Buscar titulo ou observacao" value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} />
        <input className="field" type="month" value={filters.month} onChange={(event) => setFilters({ ...filters, month: event.target.value })} />
        <select className="field" value={filters.extension} onChange={(event) => setFilters({ ...filters, extension: event.target.value })}><option value="">Todos os formatos</option><option value="png">Imagem PNG</option><option value="jpg">Imagem JPG</option><option value="pdf">PDF</option><option value="csv">CSV</option><option value="xlsx">XLSX</option></select>
      </section>
      {items.length === 0 ? <EmptyState title="Nenhuma escala publicada" description="Use Adicionar arquivo para iniciar o feed de escalas." /> : (
        <section className="mx-auto max-w-5xl space-y-5">
          {items.map((item) => {
            const image = ['png', 'jpg', 'jpeg'].includes(item.extension)
            const download = apiUrl(`portal/shift_attachments_download.php?id=${item.id}`)
            const preview = apiUrl(`portal/shift_attachments_download.php?id=${item.id}&preview=1`)
            return (
              <article className="card overflow-hidden p-0" key={item.id}>
                <div className="flex flex-col gap-5 p-5 sm:flex-row sm:items-start sm:justify-between">
                  <div><span className="status-badge status-info">{item.reference_month}</span><h3 className="mt-3 text-xl font-bold text-white">{item.title}</h3><p className="mt-2 text-sm text-slate-400">{item.notes || 'Sem observacoes.'}</p><p className="mt-4 text-xs text-slate-500">Publicado por {item.uploaded_by} em {formatDateTime(item.uploaded_at)} · {item.extension.toUpperCase()}</p></div>
                  <a className="btn-secondary shrink-0 gap-2" href={download}><Icon name={image ? 'eye' : 'download'} /> {image ? 'Visualizar' : 'Baixar'}</a>
                </div>
                {image && <a href={preview} target="_blank" rel="noreferrer"><img alt={`Escala ${item.title}`} className="max-h-[680px] w-full border-t border-white/10 bg-slate-950/50 object-contain" loading="lazy" src={preview} /></a>}
              </article>
            )
          })}
        </section>
      )}
      {uploadOpen && <UploadShift limits={resource.data?.limits} onClose={() => setUploadOpen(false)} onUploaded={async (message) => { notify(message); await resource.refresh() }} />}
    </div>
  )
}
