// Formatação ÚNICA de tokens no front (fix/uat-round-polish, item 2). Antes o mesmo
// saldo aparecia de três jeitos — "300,4000" (extrato), "300.4000" (saques), "300"
// (painel). Aqui o separador é SEMPRE pt-BR (vírgula decimal, ponto de milhar); o que
// muda por contexto é só o número de casas, e isso é decisão documentada:
//
//   - SALDO/GANHO da performer  → formatTokens(v): casas quando fracionário (o crédito
//     da performer fraciona — 80% de 2 = 1,6), inteiro quando inteiro. Até 4 casas
//     (a escala do ledger), zeros à direita cortados: 300,4 e não 300,4000.
//   - PREÇO / quantidade inteira → formatTokens(v) também devolve inteiro para valor
//     inteiro; um preço nunca é fracionário, então sai "60", "300".
//
// Nenhuma exibição de token deve usar toFixed/replace/toLocaleString ad-hoc: importa
// daqui. (Valores em REAIS continuam com toLocaleString BRL — outra unidade.)

/**
 * @param {number|string} value  token amount (aceita a string decimal do ledger)
 * @param {number|null} decimals casas fixas; null = automático (inteiro sem casas,
 *                                fracionário com até 4, zeros à direita cortados)
 */
export function formatTokens(value, decimals = null) {
    const n = typeof value === 'string' ? parseFloat(value) : Number(value)
    if (!Number.isFinite(n)) return '0'

    if (decimals === null) {
        return n.toLocaleString('pt-BR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: Number.isInteger(n) ? 0 : 4,
        })
    }

    return n.toLocaleString('pt-BR', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
}
