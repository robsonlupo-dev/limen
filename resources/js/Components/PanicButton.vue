<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Saída rápida. Existe para um cenário concreto: alguém entra na sala e o
// membro precisa tirar a Limen da tela AGORA, sem hesitar num menu.
//
// Comportamento esperado (o projeto não tem Vitest/Jest — este bloco é o
// contrato do componente, verificar à mão ao mexer):
//  1. Clique no botão OU Escape duas vezes em menos de 500ms dispara a saída.
//     Um Escape sozinho não faz nada (senão fechar um modal viraria evasão).
//  2. Na saída, nesta ordem: limpa sessionStorage, reescreve a entrada atual
//     do histórico para "/", DISPARA o POST de logout, e sai com
//     location.replace() — replace() não empilha entrada nova, então o Voltar
//     do navegador não devolve a página.
//  3. O POST de logout vai com keepalive: o navegador o mantém em voo depois
//     que a página morre. Por isso ele NÃO é aguardado — ver nota abaixo.
//  4. O destino vem de props.panicRedirectUrl (config/app.php →
//     PANIC_REDIRECT_URL). URL não-http(s) ou ausente cai no padrão.
//
// Por que o logout não é aguardado: `await fetch(...)` antes do redirect
// prenderia o membro na tela da Limen pelo tempo do round-trip — e com rede
// ruim ou captive portal, isso é dezenas de segundos olhando exatamente para o
// que ele precisa esconder. keepalive existe para este caso: o request sobrevive
// à navegação, então dá para redirecionar na hora e deixar o logout terminar
// sozinho. Aguardar o fetch anularia o motivo de usar keepalive.
//
// Limites conhecidos, para não vender o que não entrega: isto NÃO apaga o
// histórico do navegador (só a entrada corrente), não apaga o cookie do gate de
// idade, e uma aba anterior já aberta na Limen continua aberta. O logout é
// best-effort: se o POST falhar (offline, CSRF expirado), a sessão continua de
// pé e reabrir o site volta logado — a saída da tela acontece de qualquer forma.
// É saída rápida, não antiforense.

const DEFAULT_URL = 'https://www.google.com.br'
const DOUBLE_ESCAPE_MS = 500

const page = usePage()

const target = computed(() => {
    const url = page.props.panicRedirectUrl
    return typeof url === 'string' && /^https?:\/\//i.test(url) ? url : DEFAULT_URL
})

let lastEscapeAt = 0

function escape() {
    try {
        window.sessionStorage.clear()
    } catch {
        // sessionStorage pode lançar em modo restrito; a saída é mais
        // importante que a limpeza, então segue.
    }

    try {
        window.history.replaceState(null, '', '/')
    } catch {
        // idem.
    }

    try {
        // Fire-and-forget: keepalive segura o request em voo depois que a
        // página morre no replace() abaixo. Sem await de propósito.
        fetch('/logout', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).catch(() => {
            // Silencia: o redirect não depende do logout.
        })
    } catch {
        // fetch pode nem existir/lançar em ambiente exótico; a saída continua.
    }

    window.location.replace(target.value)
}

function onKeydown(event) {
    if (event.key !== 'Escape') {
        return
    }

    const now = Date.now()

    if (now - lastEscapeAt <= DOUBLE_ESCAPE_MS) {
        lastEscapeAt = 0
        escape()
        return
    }

    lastEscapeAt = now
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
    <!--
      Link de texto no header (pedido do PO, ago/2026): substitui o disco flutuante
      que vivia teleportado na camada de topo. O disco lia como "botão de fechar" e no
      UAT confundia o membro; um link ROTULADO ("Panic Button") diz o que é sem ele
      precisar adivinhar. É montado inline pelos layouts, ao lado do nome do usuário.

      Consequência assumida da mudança: como link inline no fluxo do header, ele NÃO
      é mais a camada de topo intocável — um modal pode cobri-lo. A saída sempre
      disponível passou a ser o DUPLO-ESCAPE (listener em `window`, dispara mesmo
      sob overlay); o link é a via descoberta/rotulada. É o duplo-Escape, não o
      z-index, que PanicButtonLayerTest agora cobra. Ao mexer no visual, preserve o
      listener de teclado — é ele o caminho que nenhuma tela cobre.

      Discreto mas legível (pedido do PO): tom `muted` com pílula de borda fina que
      o marca como controle intencional, e o RÓTULO em texto para não depender de
      glifo. O hover puxa para `danger` — reforça "saída de emergência" sem gritar.
    -->
    <button
        type="button"
        aria-label="Panic Button — saída rápida: sai da Limen e vai para outro site"
        title="Saída rápida"
        class="inline-flex items-center gap-1.5 rounded border border-frame/70 px-2 py-1 text-xs text-muted transition-colors hover:text-danger hover:border-danger/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger/60"
        @click="escape"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-3.5 w-3.5"
            aria-hidden="true"
        >
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <path d="M16 17l5-5-5-5M21 12H9" />
        </svg>
        Panic Button
    </button>
</template>
