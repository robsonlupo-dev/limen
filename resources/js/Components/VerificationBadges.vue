<script setup>
import { computed } from 'vue'
import { verifiedLabel } from '@/lib/worlds'

/**
 * Selos de verificação da performer, exibidos abaixo do nome no card do
 * catálogo e no perfil público.
 *
 * Convive com o VerifiedBadge (selo dourado, AO LADO do nome), que segue sendo
 * a marca de identidade. Esta linha é o detalhamento: fica ABAIXO do nome e
 * enumera os sinais um a um. Decisão do PO — não colapsar os dois.
 *
 * O que NÃO volta: a pílula dourada "Verificada" solta no card público e o
 * "· Verificada" na linha de categoria do perfil. Eram uma terceira e quarta
 * cópia do mesmo sinal.
 *
 * Ainda não existe: "ID verificado" (documento). O espaço está reservado
 * abaixo — é Sprint 9 futuro, e o dado que hoje chega ao front (`is_verified`)
 * não distingue documento de selfie.
 */
const props = defineProps({
    isVerified: { type: Boolean, default: false },
    emailVerified: { type: Boolean, default: false },
    // Mundo da performer, para a concordância. Sem ela cai no feminino.
    category: { type: String, default: null },
    // 'sm' no card do catálogo, 'md' no perfil (mesmo text-xs, só respira mais).
    size: { type: String, default: 'sm' },
})

const verified = computed(() => verifiedLabel(props.category))

const pill = computed(() =>
    props.size === 'md'
        ? 'text-xs px-2.5 py-1 gap-1.5'
        : 'text-xs px-2 py-0.5 gap-1'
)
</script>

<template>
    <div v-if="isVerified || emailVerified" class="flex items-center gap-1.5 flex-wrap">
        <span
            v-if="isVerified"
            :class="pill"
            class="inline-flex items-center rounded-full border border-green-500/25 bg-green-500/10 font-medium text-green-400"
            title="Identidade verificada pelo Limen"
        >
            <svg width="11" height="11" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="shrink-0" aria-hidden="true">
                <path d="M4 10.5l4 4 8-9" />
            </svg>
            {{ verified.label }}
        </span>

        <span
            v-if="emailVerified"
            :class="pill"
            class="inline-flex items-center rounded-full border border-frame bg-surface-2 text-muted"
            title="E-mail confirmado"
        >
            <svg width="11" height="11" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="shrink-0" aria-hidden="true">
                <path d="M4 10.5l4 4 8-9" />
            </svg>
            Email
        </span>

        <!-- Reservado: badge "ID verificado" (documento) entra aqui no Sprint 9. -->
    </div>
</template>
