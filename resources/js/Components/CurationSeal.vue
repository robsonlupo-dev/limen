<script setup>
import { computed } from 'vue'
import { tierBadgeLabel } from '@/lib/performerAttributes'

// Selo de CURADORIA (Maison / Select) ao lado do nome no perfil — mesmo estilo
// discreto do card do catálogo. É distinto da VERIFICAÇÃO (VerifiedBadge, o selo
// dourado): verificação é fato conferido, curadoria é juízo da Limen. Só os tiers
// badgeáveis viram pill (tierBadgeLabel = 'maison'/'select'); os demais → nada,
// então a performer sem curadoria não ganha selo algum.
//
// Maison = pill de BORDA dourada; Select = pill de FUNDO sutil. Não expõe tier de
// MEMBRO (é a curadoria da PERFORMER, dado dela sobre ela mesma).
const props = defineProps({
    tier: { type: String, default: null },
})

const label = computed(() => tierBadgeLabel(props.tier))
const isMaison = computed(() => props.tier === 'maison')
</script>

<template>
    <span
        v-if="label"
        class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.15em] text-limen-gold"
        :class="isMaison ? 'border border-limen-gold/60' : 'bg-limen-gold/15'"
        :title="`Curadoria Limen · ${label}`"
    >
        {{ label }}
    </span>
</template>
