<script setup>
// Elemento-assinatura do painel do membro (feat/member-nav-restructure): o ARCO
// do portal Limen usado como ESTRUTURA, não enfeite. Todo título de seção "nasce
// sob uma curva fina dourada" — a mesma silhueta do limiar/portal da marca. O
// painel é reconhecível como Limen mesmo com o logo fora da tela.
//
// Uso: rótulo em caixa alta pequena e espaçada + título em serifada de display.
// Dourado é ACENTO (a curva e o rótulo), nunca preenchimento de área.
defineProps({
    // Rótulo curto em small-caps sobre a curva (ex.: "Seu clube").
    eyebrow: { type: String, default: '' },
    // Título serifado de display.
    title: { type: String, required: true },
    // Subtítulo opcional, uma linha discreta abaixo do título.
    subtitle: { type: String, default: '' },
    // Tag semântica do título (h1 no topo da página, h2 nas seções internas).
    as: { type: String, default: 'h2' },
})
</script>

<template>
    <div class="space-y-2">
        <!-- A curva dourada + o rótulo em caixa alta. O arco é uma cúpula rasa,
             a silhueta do portal. `aria-hidden`: é ornamento estrutural. -->
        <div v-if="eyebrow" class="flex items-center gap-2.5">
            <svg
                width="34"
                height="11"
                viewBox="0 0 34 11"
                fill="none"
                class="shrink-0 text-gold"
                aria-hidden="true"
            >
                <path
                    d="M1 10C1 10 5.5 1.5 17 1.5C28.5 1.5 33 10 33 10"
                    stroke="currentColor"
                    stroke-width="1.1"
                    stroke-linecap="round"
                />
            </svg>
            <span class="text-[11px] font-medium uppercase tracking-[0.28em] text-gold/80">
                {{ eyebrow }}
            </span>
        </div>

        <component :is="as" class="font-serif text-cream" :class="as === 'h1' ? 'text-4xl leading-tight' : 'text-2xl'">
            {{ title }}
        </component>

        <p v-if="subtitle" class="text-sm text-muted">{{ subtitle }}</p>
    </div>
</template>
