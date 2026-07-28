<script setup>
import { computed } from 'vue'
import { verifiedLabel } from '@/lib/worlds'
import { tierBadgeLabel } from '@/lib/performerAttributes'

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
    // Curadoria (verificada/select/maison), ou null. Entra AQUI e não em cada
    // card porque a posição pedida é "na mesma linha dos selos, depois deles" —
    // e são quatro superfícies (dois cards, dois perfis). Quatro cópias da
    // mesma marcação divergiriam no primeiro ajuste de estilo.
    tier: { type: String, default: null },
})

const verified = computed(() => verifiedLabel(props.category))

// null para `verificada` e para null: o tier base não vira selo — ver
// BADGED_TIERS. Maison ganha destaque; Select fica no dourado discreto.
const tierLabel = computed(() => tierBadgeLabel(props.tier))
const isMaison = computed(() => props.tier === 'maison')

const pill = computed(() =>
    props.size === 'md'
        ? 'text-xs px-2.5 py-1 gap-1.5'
        : 'text-xs px-2 py-0.5 gap-1'
)
</script>

<template>
    <div v-if="isVerified || emailVerified || tierLabel" class="flex items-center gap-1.5 flex-wrap">
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

        <!-- Curadoria. Último da linha de propósito: os selos anteriores são
             VERIFICAÇÃO (fato conferido pela plataforma) e este é CURADORIA
             (juízo dela). Vir depois mantém a leitura "quem ela é" antes de
             "como a classificamos", e o estilo discreto impede o selo de
             curadoria de gritar mais alto que o de identidade. -->
        <span
            v-if="tierLabel"
            :class="[
                pill,
                isMaison
                    ? 'border-gold/50 bg-gold/15 text-gold font-medium'
                    : 'border-gold/25 bg-gold/5 text-gold',
            ]"
            class="inline-flex items-center rounded-full border"
            :title="`Curadoria Limen · ${tierLabel}`"
        >
            <span v-if="isMaison" aria-hidden="true" class="text-[9px] leading-none">◆</span>
            {{ tierLabel }}
        </span>
    </div>
</template>
