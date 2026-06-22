import { useMemo, useRef, useState } from 'react'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'
import { FileTypeBadge, FilterBar, InlineAlert, MetricCard, SectionHeader } from '../components/ui/Primitives'
import { useResource } from '../hooks/useResource'
import { apiUrl, post, postForm } from '../lib/api'
import { formatDateTime } from '../lib/format'

const DEFAULT_MAX_FILE_BYTES = 10 * 1024 * 1024
const DEFAULT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xlsx', 'csv', 'txt', 'md', 'png', 'jpg', 'jpeg']

function extensionOf(name) {
  const parts = String(name).toLowerCase().split('.')
  return parts.length > 1 ? parts.pop() : ''
}

function formatBytes(bytes) {
  const value = Number(bytes) || 0
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  return `${(value / (1024 * 1024)).toFixed(1)} MB`
}

function validateSelectedFile(file, limits) {
  if (!file) return 'Selecione um arquivo.'
  const allowed = limits?.allowed_extensions || DEFAULT_EXTENSIONS
  const maxBytes = limits?.max_file_bytes || DEFAULT_MAX_FILE_BYTES
  const extension = extensionOf(file.name)
  if (!allowed.includes(extension)) return 'Este tipo de arquivo não é permitido.'
  if (file.size < 1) return 'O arquivo está vazio.'
  if (file.size > maxBytes) return `O arquivo excede o limite de ${formatBytes(maxBytes)}.`
  return ''
}

function UploadPanel({ limits, onClose, onUploaded }) {
  const inputRef = useRef(null)
  const [file, setFile] = useState(null)
  const [dragging, setDragging] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [metadata, setMetadata] = useState({
    title: '',
    category: 'Procedimento',
    description: '',
    visibility: 'internal',
  })
  const validation = validateSelectedFile(file, limits)

  function choose(selected) {
    const nextFile = selected?.[0] || null
    setFile(nextFile)
    setError(validateSelectedFile(nextFile, limits))
    if (nextFile && !metadata.title) {
      setMetadata((current) => ({
        ...current,
        title: nextFile.name.replace(/\.[^.]+$/, '').slice(0, 180),
      }))
    }
  }

  async function submit(event) {
    event.preventDefault()
    const fileError = validateSelectedFile(file, limits)
    if (fileError) {
      setError(fileError)
      return
    }
    if (!metadata.title.trim() || !metadata.category.trim()) {
      setError('Informe título e categoria.')
      return
    }

    const body = new FormData()
    body.append('document', file)
    Object.entries(metadata).forEach(([key, value]) => body.append(key, value))
    setSubmitting(true)
    setError('')
    try {
      const result = await postForm('portal/documents.php', body)
      await onUploaded(result.mensagem)
      onClose()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/70 backdrop-blur-sm">
      <button aria-label="Fechar envio de documento" className="absolute inset-0" onClick={onClose} type="button" />
      <form className="relative h-full w-full max-w-xl overflow-y-auto border-l border-white/10 bg-slate-950 p-6 shadow-2xl" onSubmit={submit}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-blue-400">Armazenamento privado</p>
            <h2 className="mt-2 text-xl font-bold text-white">Adicionar arquivo</h2>
            <p className="mt-2 text-sm text-slate-400">O backend valida extensão, MIME real, conteúdo, tamanho e integridade.</p>
          </div>
          <button className="rounded-lg p-2 text-slate-400 hover:bg-white/5 hover:text-white" onClick={onClose} type="button" aria-label="Fechar">
            <Icon name="close" />
          </button>
        </div>

        <button
          className={`mt-6 grid w-full place-items-center rounded-2xl border border-dashed px-5 py-10 text-center transition ${dragging ? 'border-blue-400 bg-blue-500/10' : 'border-white/15 bg-slate-900/50 hover:border-blue-500/50'}`}
          onClick={() => inputRef.current?.click()}
          onDragEnter={(event) => { event.preventDefault(); setDragging(true) }}
          onDragLeave={(event) => { event.preventDefault(); setDragging(false) }}
          onDragOver={(event) => event.preventDefault()}
          onDrop={(event) => {
            event.preventDefault()
            setDragging(false)
            choose(event.dataTransfer.files)
          }}
          type="button"
        >
          <Icon name="upload" className="h-8 w-8 text-blue-400" />
          <strong className="mt-3 text-sm text-slate-200">Clique para escolher ou arraste o arquivo</strong>
          <span className="mt-2 text-xs text-slate-500">{(limits?.allowed_extensions || DEFAULT_EXTENSIONS).join(', ')} · até {formatBytes(limits?.max_file_bytes || DEFAULT_MAX_FILE_BYTES)}</span>
        </button>
        <input
          ref={inputRef}
          className="hidden"
          type="file"
          accept={(limits?.allowed_extensions || DEFAULT_EXTENSIONS).map((item) => `.${item}`).join(',')}
          onChange={(event) => choose(event.target.files)}
        />

        {file && (
          <div className="file-preview-panel mt-4 p-4">
            <div className="flex items-start gap-3">
              <div className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-blue-500/10 text-blue-300"><Icon name="file" /></div>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="truncate text-sm font-semibold text-slate-200">{file.name}</p>
                  <FileTypeBadge extension={extensionOf(file.name)} />
                </div>
                <p className="mt-1 text-xs text-slate-500">{formatBytes(file.size)} · {file.type || 'MIME informado pelo navegador indisponível'}</p>
                <span className={`status-badge mt-3 ${validation ? 'status-danger' : 'status-success'}`}>{validation || 'Validação inicial aprovada'}</span>
              </div>
            </div>
          </div>
        )}

        <div className="mt-6 grid gap-4">
          <label className="label">Título
            <input className="field mt-2" maxLength="180" required value={metadata.title} onChange={(event) => setMetadata({ ...metadata, title: event.target.value })} />
          </label>
          <label className="label">Categoria
            <input className="field mt-2" maxLength="60" required value={metadata.category} onChange={(event) => setMetadata({ ...metadata, category: event.target.value })} />
          </label>
          <label className="label">Descrição
            <textarea className="field mt-2 min-h-24 resize-y" maxLength="2000" value={metadata.description} onChange={(event) => setMetadata({ ...metadata, description: event.target.value })} />
          </label>
          <label className="label">Visibilidade
            <select className="field mt-2" value={metadata.visibility} onChange={(event) => setMetadata({ ...metadata, visibility: event.target.value })}>
              <option value="internal">Interna</option>
              <option value="management">Gestão</option>
            </select>
          </label>
        </div>

        {error && <InlineAlert className="mt-4" tone="danger" title="Não foi possível enviar">{error}</InlineAlert>}
        <div className="mt-6 flex justify-end gap-3">
          <button className="btn-secondary" disabled={submitting} onClick={onClose} type="button">Cancelar</button>
          <button className="btn-primary" disabled={submitting || Boolean(validation)} type="submit">{submitting ? 'Enviando...' : 'Confirmar envio'}</button>
        </div>
      </form>
    </div>
  )
}

export function DocumentsPage({ notify }) {
  const resource = useResource('portal/documents.php')
  const [uploadOpen, setUploadOpen] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [filters, setFilters] = useState({ search: '', extension: '', date: '', category: '', uploadedBy: '' })
  const items = useMemo(() => resource.data?.items || [], [resource.data?.items])
  const filtered = useMemo(() => {
    const search = filters.search.trim().toLowerCase()
    const category = filters.category.trim().toLowerCase()
    const uploadedBy = filters.uploadedBy.trim().toLowerCase()
    return items.filter((item) => (
      (!search || item.title.toLowerCase().includes(search) || item.original_name.toLowerCase().includes(search))
      && (!filters.extension || item.extension === filters.extension)
      && (!filters.date || String(item.uploaded_at).slice(0, 10) === filters.date)
      && (!category || item.category.toLowerCase().includes(category))
      && (!uploadedBy || item.uploaded_by.toLowerCase().includes(uploadedBy))
    ))
  }, [filters, items])

  async function remove(item) {
    if (!window.confirm(`Remover "${item.title}" da listagem? O evento ficará registrado em auditoria.`)) return
    setDeletingId(item.id)
    try {
      const result = await post('portal/documents_delete.php', { id: item.id })
      notify(result.mensagem)
      await resource.refresh()
    } catch (error) {
      notify(error.message, 'error')
    } finally {
      setDeletingId(null)
    }
  }

  if (resource.loading) return <LoadingState label="Carregando documentos" />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  return (
    <div className="space-y-5">
      <section className="card overflow-hidden">
        <SectionHeader
          eyebrow="Conhecimento operacional"
          title="Biblioteca de documentos"
          description="Procedimentos, manuais, evidências e instruções armazenados fora do webroot e acessados somente por endpoint autenticado."
          action={resource.data?.can_upload && (
            <button className="btn-primary gap-2" onClick={() => setUploadOpen(true)} type="button">
              <Icon name="upload" className="h-4 w-4" /> Adicionar arquivo
            </button>
          )}
        />
      </section>

      <section className="grid gap-4 md:grid-cols-3">
        <MetricCard detail="Arquivos disponíveis após os filtros atuais." icon="file" label="Documentos" value={filtered.length} />
        <MetricCard detail="Itens marcados para visibilidade de gestão." icon="shield" label="Gestão" tone="warning" value={filtered.filter((item) => item.visibility === 'management').length} />
        <MetricCard detail="Formatos distintos presentes na biblioteca." icon="download" label="Formatos" tone="info" value={new Set(items.map((item) => item.extension)).size} />
      </section>

      <FilterBar>
        <input aria-label="Buscar por nome ou título" className="field" placeholder="Nome ou título" value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} />
        <select aria-label="Filtrar por tipo de arquivo" className="field" value={filters.extension} onChange={(event) => setFilters({ ...filters, extension: event.target.value })}>
          <option value="">Todos os tipos</option>
          {(resource.data?.limits?.allowed_extensions || DEFAULT_EXTENSIONS).map((extension) => <option value={extension} key={extension}>{extension.toUpperCase()}</option>)}
        </select>
        <input aria-label="Filtrar por categoria" className="field" placeholder="Categoria" value={filters.category} onChange={(event) => setFilters({ ...filters, category: event.target.value })} />
        <input aria-label="Filtrar por responsável" className="field" placeholder="Responsável" value={filters.uploadedBy} onChange={(event) => setFilters({ ...filters, uploadedBy: event.target.value })} />
        <input className="field" aria-label="Data de envio" type="date" value={filters.date} onChange={(event) => setFilters({ ...filters, date: event.target.value })} />
      </FilterBar>

      {filtered.length === 0 ? (
        <EmptyState title="Nenhum documento encontrado" description={items.length ? 'Ajuste os filtros para localizar outros documentos.' : 'Use “Adicionar arquivo” para cadastrar o primeiro documento interno.'} />
      ) : (
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {filtered.map((item) => (
            <article className="card card-interactive flex min-h-56 flex-col" key={item.id}>
              <div className="flex items-start justify-between gap-3">
                <div className="grid h-11 w-11 place-items-center rounded-xl bg-blue-500/10 text-blue-300"><Icon name="file" /></div>
                <div className="flex gap-2">
                  <FileTypeBadge extension={item.extension} />
                  {item.visibility === 'management' && <span className="status-badge status-warning">Gestão</span>}
                </div>
              </div>
              <h3 className="mt-4 line-clamp-2 font-bold text-white">{item.title}</h3>
              <p className="mt-1 truncate text-xs text-slate-500">{item.original_name}</p>
              <p className="mt-3 line-clamp-2 text-sm text-slate-400">{item.description || 'Sem descrição.'}</p>
              <div className="mt-auto pt-5 text-xs text-slate-500">
                <p>{item.category} · {formatBytes(item.size_bytes)}</p>
                <p className="mt-1">{item.uploaded_by} · {formatDateTime(item.uploaded_at)}</p>
              </div>
              <div className="mt-4 flex gap-2 border-t border-white/5 pt-4">
                <a className="btn-secondary flex-1 gap-2" href={apiUrl(`portal/documents_download.php?id=${item.id}`)}>
                  <Icon name="download" className="h-4 w-4" /> Baixar
                </a>
                {resource.data?.can_upload && (
                  <button className="btn-secondary px-3 text-red-300" disabled={deletingId === item.id} onClick={() => remove(item)} type="button" aria-label={`Remover ${item.title}`}>
                    <Icon name="trash" className="h-4 w-4" />
                  </button>
                )}
              </div>
            </article>
          ))}
        </section>
      )}

      {uploadOpen && (
        <UploadPanel
          limits={resource.data?.limits}
          onClose={() => setUploadOpen(false)}
          onUploaded={async (message) => {
            notify(message)
            await resource.refresh()
          }}
        />
      )}
    </div>
  )
}
