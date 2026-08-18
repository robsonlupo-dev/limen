<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Saída rápida. Existe para um cenário concreto: alguém entra na sala e o
// usuário precisa tirar a Limen da tela AGORA, sem hesitar num menu.
//
// Comportamento esperado (o projeto não tem Vitest/Jest — este bloco é o
// contrato do componente, verificar à mão ao mexer):
//  1. Clique no ÍCONE OU Escape duas vezes em menos de 500ms dispara a saída.
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
// APRESENTAÇÃO (decisão do PO, fix/panel-polish-v1): o botão flutuante é
// DESKTOP-ONLY (`hidden md:*`). No CELULAR a saída nativa — bloquear o aparelho
// — é mais rápida que qualquer botão nosso, então a pílula some e a saída da
// conta fica no menu do avatar/"Sair". O DUPLO-ESCAPE continua ativo em TODA
// largura (o listener não depende do botão estar renderizado). No desktop o
// botão é um ÍCONE pequeno e discreto no canto SUPERIOR-ESQUERDO, em vermelho
// (danger); o rótulo "Panic Button" (em inglês, de propósito: menos legível de
// relance para quem passa perto) + o atalho Esc só aparecem no HOVER/FOCO.
//
// Por que o logout não é aguardado: `await fetch(...)` antes do redirect
// prenderia o usuário na tela da Limen pelo tempo do round-trip — e com rede
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
      DUAS vias para a mesma saída (escape()), cada uma cobrindo um buraco da outra:

      1. ÍCONE FLUTUANTE (desktop) — teleportado para a raiz em z-[10001], a via
         VISÍVEL e INTOCÁVEL. O Teleport a tira de qualquer stacking context de
         layout, e o 10001 fica UM acima do teto do projeto (IntroAnimation 10000,
         AgeGateModal 9999): nada a cobre nem engole o clique. Fica no canto
         SUPERIOR-ESQUERDO, pequeno e em cor de ALERTA (`danger`), distinta do
         dourado da marca, para não ser lida como "fechar" (regressão do UAT 63).
         `hidden md:block`: NÃO aparece no celular — lá a saída nativa (bloquear o
         aparelho) é mais rápida, e a saída da conta fica no menu do avatar.
         Invariante (PanicButtonLayerTest): NENHUM outro componente usa z-index
         >= 10001 — overlay novo entra abaixo, não suba a camada.

      2. DUPLO-ESCAPE (listener em `window`, no <script>) — a via de TECLADO,
         SEMPRE ATIVA (independe do ícone estar renderizado), então funciona no
         celular com teclado e mesmo com o ícone escondido ou coberto.

      O rótulo "Panic Button" e o atalho só aparecem no HOVER/FOCO — o ícone
      sozinho é discreto; quem precisa da saída conhece o gesto.
    -->
    <Teleport to="body">
        <div class="pnc-exit fixed left-4 top-4 z-[10001] hidden md:block">
            <button
                type="button"
                aria-label="Panic Button — sair da Limen agora. Atalho: pressione Esc duas vezes."
                class="pnc-btn group relative flex h-9 w-9 items-center justify-center rounded-full border border-danger/60 bg-danger/90 text-cream shadow-lg shadow-black/40 transition-colors hover:bg-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-danger/70"
                @click="escape"
            >
                <!-- Ícone de porta/seta de saída. -->
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="h-4 w-4"
                    aria-hidden="true"
                >
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <path d="M16 17l5-5-5-5M21 12H9" />
                </svg>

                <!-- Rótulo revelado só no hover/foco. Posicionado ABSOLUTO (nunca
                     empurra layout) e controlado por opacidade — a transição
                     respeita prefers-reduced-motion (motion-safe). -->
                <span
                    class="pointer-events-none absolute left-full top-1/2 ml-2 -translate-y-1/2 whitespace-nowrap rounded-md border border-danger/40 bg-background/95 px-2.5 py-1.5 text-left opacity-0 shadow-lg shadow-black/40 motion-safe:transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                >
                    <span class="block text-xs font-semibold text-cream">Panic Button</span>
                    <span class="block text-[10px] text-muted">Esc · Esc para sair</span>
                </span>
            </button>
        </div>
    </Teleport>
</template>
