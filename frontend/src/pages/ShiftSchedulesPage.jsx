import { useEffect, useMemo, useRef, useState } from 'react'
import { Icon } from '../components/ui/Icon'
import { FileTypeBadge, FilterBar, InlineAlert, MetricCard, SectionHeader } from '../components/ui/Primitives'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { useResource } from '../hooks/useResource'
import { apiUrl, postForm } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { localDate, queryString } from '../lib/operational'

const accepted = ['.png', '.jpg', '.jpeg', '.pdf', '.csv', '.xls', '.xlsx']
const allowedFormats = 'PNG, JPG, JPEG, PDF, CSV, XLS ou XLSX'
const imageExtensions = ['png', 'jpg', 'jpeg']
const spreadsheetExtensions = ['csv', 'xls', 'xlsx']

function extensionFromName(name = '') {
  const match = String(name).toLowerCase().match(/\.([a-z0-9]+)$/)
  return match?.[1] || ''
}

function isImageExtension(extension) {
  return imageExtensions.includes(String(extension).toLowerCase())
}

function isSpreadsheetExtension(extension) {
  return spreadsheetExtensions.includes(String(extension).toLowerCase())
}

function formatBytes(bytes) {
  const value = Number(bytes || 0)
  if (value >= 1048576) return `${(value / 1048576).toFixed(1)} MB`
  return `${Math.max(1, Math.ceil(value / 1024))} KB`
}

function authorInitials(name = '') {
  const parts = String(name || 'Portal SDK').trim().split(/\s+/).filter(Boolean)
  return parts.slice(0, 2).map((part) => part[0]?.toUpperCase()).join('') || 'PS'
}

function monthLabel(value) {
  if (!/^\d{4}-\d{2}$/.test(String(value || ''))) return value || 'Sem mês'
  return new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(new Date(`${value}-01T12:00:00`))
}

function attachmentLabel(extension) {
  const ext = String(extension || '').toLowerCase()
  if (isImageExtension(ext)) return 'Imagem da escala'
  if (ext === 'pdf') return 'PDF da escala'
  if (isSpreadsheetExtension(ext)) return 'Planilha da escala'
  return 'Anexo da escala'
}

function UploadPreview({ file, previewUrl, imageFailed, onImageError }) {
  const extension = extensionFromName(file.name)
  const image = isImageExtension(extension)
  const spreadsheet = isSpreadsheetExtension(extension)
  const label = image ? 'Imagem selecionada' : extension === 'pdf' ? 'PDF selecionado' : spreadsheet ? 'Planilha selecionada' : 'Arquivo selecionado'

  return (
    <div className="file-preview-panel p-4">
      {image && previewUrl && !imageFailed ? (
        <img alt="Preview da escala selecionada" className="mb-3 max-h-72 w-full rounded-lg bg-slate-950 object-contain" onError={onImageError} src={previewUrl} />
      ) : (
        <div className="mb-3 flex items-center gap-3 rounded-lg border border-white/10 bg-slate-900/70 p-4">
          <span className="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-blue-500/10 text-blue-300">
            <Icon name={image ? 'file' : 'download'} />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-200">{label}</p>
            <p className="mt-1 text-xs text-slate-500">{image ? 'Preview indisponível neste navegador.' : 'O anexo será exibido como card no feed.'}</p>
          </div>
        </div>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <p className="truncate text-sm font-semibold text-slate-200">{file.name}</p>
        <FileTypeBadge extension={extension} />
      </div>
      <p className="mt-1 text-xs text-slate-500">{formatBytes(file.size)}</p>
    </div>
  )
}

function UploadShift({ limits, onClose, onUploaded }) {
  const inputRef = useRef(null)
  const [file, setFile] = useState(null)
  const [imageFailed, setImageFailed] = useState(false)
  const [dragging, setDragging] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [metadata, setMetadata] = useState({
    title: '', reference_month: localDate().slice(0, 7), notes: '',
  })
  const previewUrl = useMemo(
    () => (file && isImageExtension(extensionFromName(file.name)) ? URL.createObjectURL(file) : ''),
    [file],
  )

  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl)
  }, [previewUrl])

  function choose(files) {
    const selected = files?.[0]
    setError('')
    setImageFailed(false)
    if (!selected) return
    const extension = extensionFromName(selected.name)
    if (!accepted.includes(`.${extension}`)) {
      setError(`Formato não permitido. Envie ${allowedFormats}.`)
      return
    }
    if (selected.size > Number(limits?.max_file_bytes || 10485760)) {
      setError('Arquivo acima do limite de 10 MB.')
      return
    }
    setFile(selected)
    if (!metadata.title) setMetadata((current) => ({ ...current, title: selected.name.replace(/\.[^.]+$/, '') }))
  }

  async function submit(event) {
    event.preventDefault()
    if (!metadata.title.trim()) return setError('Título é obrigatório.')
    if (!file) return setError('Selecione um arquivo para publicar.')
    setLoading(true)
    setError('')
    try {
      const body = new FormData()
      body.append('attachment', file)
      Object.entries(metadata).forEach(([key, value]) => body.append(key, value))
      const result = await postForm('portal/shift_attachments.php', body)
      await onUploaded(result.mensagem || 'Escala publicada com sucesso.')
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
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-blue-400">Publicação segura</p>
            <h2 className="mt-2 text-xl font-bold text-white">Publicar Escala de Sábado</h2>
            <p className="mt-2 text-sm text-slate-400">Publique imagens, PDFs ou planilhas da escala de sábado.</p>
          </div>
          <button className="text-sm text-slate-400 hover:text-slate-200" onClick={onClose} type="button">Cancelar</button>
        </div>
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
          <strong className="mt-3 text-sm text-slate-200">Clique ou arraste a escala</strong>
          <span className="mt-1 text-xs text-slate-500">{allowedFormats}, até 10 MB</span>
        </button>
        <input ref={inputRef} accept={accepted.join(',')} className="hidden" onChange={(event) => choose(event.target.files)} type="file" />
        {file && <UploadPreview file={file} imageFailed={imageFailed} onImageError={() => setImageFailed(true)} previewUrl={previewUrl} />}
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="label sm:col-span-2">Título *<input className="field mt-2" maxLength="180" required value={metadata.title} onChange={(event) => setMetadata({ ...metadata, title: event.target.value })} /></label>
          <label className="label">Mês de referência<input className="field mt-2" required type="month" value={metadata.reference_month} onChange={(event) => setMetadata({ ...metadata, reference_month: event.target.value })} /></label>
          <label className="label sm:col-span-2">Observação<textarea className="field mt-2 min-h-24 resize-y" maxLength="2000" value={metadata.notes} onChange={(event) => setMetadata({ ...metadata, notes: event.target.value })} /></label>
        </div>
        {error && <InlineAlert tone="danger" title="Falha no upload">{error}</InlineAlert>}
        <div className="flex justify-end gap-2"><button className="btn-secondary" onClick={onClose} type="button">Cancelar</button><button className="btn-primary" disabled={loading || !file} type="submit">{loading ? 'Publicando...' : 'Publicar escala'}</button></div>
      </form>
    </div>
  )
}

function AttachmentCard({ item, download, preview }) {
  const extension = String(item.extension || '').toLowerCase()
  const spreadsheet = isSpreadsheetExtension(extension)
  return (
    <a className="mt-4 flex items-center justify-between gap-4 rounded-xl border border-white/10 bg-slate-950/50 p-4 transition hover:border-blue-500/30 hover:bg-slate-900/80" href={extension === 'pdf' ? preview : download} rel="noreferrer" target={extension === 'pdf' ? '_blank' : undefined}>
      <div className="flex min-w-0 items-center gap-3">
        <span className={`grid h-12 w-12 shrink-0 place-items-center rounded-lg ${spreadsheet ? 'bg-emerald-500/10 text-emerald-300' : 'bg-red-500/10 text-red-300'}`}>
          <Icon name={spreadsheet ? 'file' : 'download'} />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <p className="truncate text-sm font-semibold text-slate-100">{item.original_name}</p>
            <FileTypeBadge extension={extension} />
          </div>
          <p className="mt-1 text-xs text-slate-500">{attachmentLabel(extension)} · {formatBytes(item.size_bytes)}</p>
        </div>
      </div>
      <span className="btn-secondary shrink-0">{extension === 'pdf' ? 'Abrir' : 'Baixar'}</span>
    </a>
  )
}

export function ShiftSchedulesPage({ notify }) {
  const [filters, setFilters] = useState({ month: '', extension: '', search: '' })
  const [brokenPreviews, setBrokenPreviews] = useState({})
  const resource = useResource(`portal/shift_attachments.php${queryString(filters)}`)
  const [uploadOpen, setUploadOpen] = useState(false)
  const items = useMemo(() => resource.data?.items || [], [resource.data?.items])

  if (resource.loading) return <LoadingState label="Carregando escalas" />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  return (
    <div className="space-y-5">
      <section className="card">
        <SectionHeader
          eyebrow="Feed operacional"
          title="Escalas de Sábado"
          description="Publicações disponíveis para consulta da equipe, com anexos protegidos por endpoint autenticado."
          action={resource.data?.can_upload && <button className="btn-primary gap-2" onClick={() => setUploadOpen(true)} type="button"><Icon name="upload" /> Publicar escala</button>}
        />
      </section>
      <section className="grid gap-4 sm:grid-cols-3">
        <MetricCard detail="Publicações retornadas pelos filtros atuais." icon="file" label="Publicações" value={items.length} />
        <MetricCard detail="Imagens com preview direto no feed." icon="download" label="Imagens" tone="info" value={items.filter((item) => isImageExtension(item.extension)).length} />
        <MetricCard detail="PDFs e planilhas para consulta ou download." icon="upload" label="Arquivos" value={items.filter((item) => !isImageExtension(item.extension)).length} />
      </section>
      <FilterBar>
        <input aria-label="Buscar título, observação ou arquivo" className="field" placeholder="Buscar título, observação ou arquivo" value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} />
        <input aria-label="Filtrar por mês de referência" className="field" type="month" value={filters.month} onChange={(event) => setFilters({ ...filters, month: event.target.value })} />
        <select aria-label="Filtrar por formato" className="field" value={filters.extension} onChange={(event) => setFilters({ ...filters, extension: event.target.value })}><option value="">Todos os formatos</option><option value="png">Imagem PNG</option><option value="jpg">Imagem JPG</option><option value="jpeg">Imagem JPEG</option><option value="pdf">PDF</option><option value="csv">CSV</option><option value="xls">XLS</option><option value="xlsx">XLSX</option></select>
      </FilterBar>
      {items.length === 0 ? <EmptyState title="Nenhuma escala publicada" description="As escalas publicadas pelos administradores aparecerão aqui." /> : (
        <section className="mx-auto max-w-4xl space-y-4">
          <h3 className="sr-only">Escalas de Sábado publicadas</h3>
          {items.map((item) => {
            const extension = String(item.extension || '').toLowerCase()
            const image = isImageExtension(extension)
            const download = apiUrl(`portal/shift_attachments_download.php?id=${item.id}`)
            const preview = apiUrl(`portal/shift_attachments_download.php?id=${item.id}&preview=1`)
            const imageBroken = brokenPreviews[item.id]
            return (
              <article className="card overflow-hidden p-0" key={item.id}>
                <div className="flex gap-4 p-5">
                  <div className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-blue-500/15 text-sm font-black text-blue-200">{authorInitials(item.uploaded_by)}</div>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                      <strong className="text-slate-100">{item.uploaded_by}</strong>
                      <span className="text-slate-600">·</span>
                      <span className="text-slate-500">{formatDateTime(item.uploaded_at)}</span>
                    </div>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                      <span className="status-badge status-info">{monthLabel(item.reference_month)}</span>
                      <FileTypeBadge extension={extension} />
                    </div>
                    <h3 className="mt-3 text-xl font-bold text-white">{item.title}</h3>
                    {item.notes && <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-300">{item.notes}</p>}
                    {image && !imageBroken ? (
                      <a className="mt-4 block overflow-hidden rounded-xl border border-white/10 bg-slate-950/50" href={preview} target="_blank" rel="noreferrer">
                        <img alt={`Anexo da escala ${item.title}`} className="max-h-[680px] w-full object-contain" loading="lazy" onError={() => setBrokenPreviews((current) => ({ ...current, [item.id]: true }))} src={preview} />
                      </a>
                    ) : (
                      <AttachmentCard download={download} item={item} preview={preview} />
                    )}
                  </div>
                </div>
              </article>
            )
          })}
        </section>
      )}
      {uploadOpen && <UploadShift limits={resource.data?.limits} onClose={() => setUploadOpen(false)} onUploaded={async (message) => { notify(message); await resource.refresh() }} />}
    </div>
  )
}
