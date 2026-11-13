// [R2] Tabela densa do portal (classes .data-table de hoje). columns:
// [{ key, label, render?(row), className? }]. Opcionais:
// - groupOf(row): rótulo do grupo; uma linha de grupo entra quando ele muda;
// - cards: abaixo de 640 px cada linha vira um cartão, com o rótulo da coluna
//   antes do valor (data-label), sem rolagem lateral;
// - caption: nome da tabela para o leitor de tela.
// A rolagem lateral (table-wrap) fica só na tabela, nunca na página.
export function Table({ columns, rows, rowKey, caption = null, groupOf = null, cards = false, className = '' }) {
  let lastGroup
  const body = []
  for (const row of rows) {
    if (groupOf) {
      const group = groupOf(row)
      if (group !== lastGroup) {
        body.push(
          <tr className="data-table-group" key={`grupo-${group}`}>
            <th colSpan={columns.length} scope="colgroup">{group}</th>
          </tr>,
        )
        lastGroup = group
      }
    }
    body.push(
      <tr key={rowKey(row)}>
        {columns.map((column) => (
          <td className={column.className} data-label={column.label} key={column.key}>
            {column.render ? column.render(row) : row[column.key]}
          </td>
        ))}
      </tr>,
    )
  }
  return (
    <div className="table-wrap">
      <table className={['data-table', cards ? 'data-table--cards' : '', className].filter(Boolean).join(' ')}>
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead>
          <tr>
            {columns.map((column) => <th className={column.className} key={column.key} scope="col">{column.label}</th>)}
          </tr>
        </thead>
        <tbody>{body}</tbody>
      </table>
    </div>
  )
}
