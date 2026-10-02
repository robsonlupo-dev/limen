import { reactive } from 'vue'

/**
 * Estado GLOBAL (singleton de módulo) do modal "Saldo insuficiente".
 *
 * O modal (`InsufficientBalanceModal.vue`) é montado UMA vez no AppLayout e lê daqui.
 * Qualquer ação de GASTO DO PRÓPRIO MEMBRO (gorjeta, presente, chamada, PPV,
 * desbloqueio de conteúdo, abrir/renovar chat, responder story, assinar fã-clube) que
 * bata em saldo insuficiente chama `promptToBuy()` em vez de só mostrar o texto mudo.
 *
 * ⚠️ NÃO é um gatiho global no HTTP de propósito: o mesmo erro `insufficient_balance`
 * (422) também é lançado no ACEITE da performer (quando o MEMBRO não tem saldo) — ali
 * o pop "Comprar tokens" iria para a pessoa errada. Por isso quem dispara é cada
 * superfície de gasto do membro, nunca o interceptador cego.
 */
const state = reactive({ open: false, message: '' })

export function useInsufficientBalance() {
    function promptToBuy(message = '') {
        state.message = message || ''
        state.open = true
    }

    function close() {
        state.open = false
    }

    return { state, promptToBuy, close }
}
