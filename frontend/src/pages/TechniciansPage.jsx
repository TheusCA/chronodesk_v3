import { useMemo, useState } from 'react'
import { useResource } from '../hooks/useResource'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { InlineAlert } from '../components/ui/Primitives'
import { NowPresenceBadge, NowPresenceLegend, PartialMarker } from '../components/ui/Presence'
import { nowLegendItem, serverClock } from '../lib/presenceLegend'

// F7 (desenho 9.9, DV8): tela Técnicos com a coluna "Situação agora" no lugar
// de "Status" (o campo status continua no JSON). A situação é a do motor no
// instante da resposta do technicians.php ("às HH:MM" do server_now), sem
// polling (P14). Presença indisponível (interruptor desligado ou falha): "—"
// com aviso (DV5, I5); cadastro fora do Mapa: presence nula com a presença
// disponível.
function SituationCell({ presence, available }) {
  if (!available) return <span title="Situação indisponível">—</span>
  if (presence === null || typeof presence !== 'object') return <span className="text-slate-500">Fora do Mapa</span>
  if (nowLegendItem(presence.situacao) === null) return <span title="Situação indisponível">—</span>
  return (
    <div className="space-y-1">
      <div className="flex flex-wrap items-center gap-1.5">
        <NowPresenceBadge situacao={presence.situacao} />
        {typeof presence.parcial === 'string' && presence.parcial !== '' && <PartialMarker time={presence.parcial} />}
      </div>
      {presence.detalhe && <small>{presence.detalhe}</small>}
      {presence.categoria && <small>Categoria: {presence.categoria}</small>}
    </div>
  )
}

function searchText(item) {
  return [item.name, item.team, item.shift, nowLegendItem(item.presence?.situacao)?.label].join(' ').toLowerCase()
}

export function TechniciansPage() {
  const resource = useResource('portal/technicians.php')
  const [search, setSearch] = useState('')
  const items = useMemo(() => {
    const term = search.trim().toLowerCase()
    return (resource.data?.items || []).filter((item) => !term || searchText(item).includes(term))
  }, [resource.data, search])

  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  const available = resource.data?.presence_meta?.disponivel === true
  const pausesAvailable = resource.data?.presence_meta?.pausas_disponiveis === true
  const clock = serverClock(resource.data?.server_now)

  return (
    <div className="space-y-5">
      <section className="card flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 className="font-bold text-white">Base operacional</h2><p className="text-sm text-slate-500">Situação de cada técnico no momento em que a tela foi aberta.</p></div>
        <input className="field sm:max-w-xs" placeholder="Buscar técnico" value={search} onChange={(event) => setSearch(event.target.value)} />
      </section>
      {!available && (
        <InlineAlert tone="warning" title="Situação agora indisponível">
          A presença não foi calculada (desligada ou com falha no servidor). A coluna aparece como "—"; recarregue a tela mais tarde.
        </InlineAlert>
      )}
      {available && !pausesAvailable && (
        <InlineAlert tone="warning" title="Pausas indisponíveis">
          O estado das pausas não pôde ser lido: quem está em pausa aparece pela escala.
        </InlineAlert>
      )}
      {/* [Ajuste A2] Busca sem resultado não é lista vazia. */}
      {items.length === 0 && search.trim() !== '' && <EmptyState title="Nenhum técnico encontrado" description="Nenhum técnico corresponde à busca." />}
      {items.length === 0 && search.trim() === '' && <EmptyState title="Nenhum técnico cadastrado" description="Os técnicos ativos de N1 e N2 aparecem aqui." />}
      {items.length > 0 && (
        <section className="card table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Equipe</th>
                <th>Turno</th>
                <th>Situação agora{clock && <span className="ml-1 font-normal normal-case tracking-normal">às {clock}</span>}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{item.name}</td>
                  <td>{item.team}</td>
                  <td>{item.shift}</td>
                  <td><SituationCell available={available} presence={item.presence ?? null} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
      <NowPresenceLegend />
    </div>
  )
}
