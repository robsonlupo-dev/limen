<script setup>
import { computed } from 'vue'
import {
    languageLabel,
    drinkLabel,
    smokeLabel,
    drinkIcon,
    smokeIcon,
    groupTags,
} from '@/lib/performerAttributes'

// Seção "Sobre mim" do perfil público. Os campos são auto-declarados pela
// performer (ver PerformerPublicResource) e TODOS são opcionais: cada bloco só
// aparece se preenchido — sem placeholder e sem "Não informado", que anunciariam
// a lacuna. Um componente só, consumido pelos dois perfis públicos
// (Performers/Show e Catalog/Show), para as duas telas nunca divergirem.
const props = defineProps({
    performer: { type: Object, required: true },
})

const tagGroups = computed(() => groupTags(props.performer.tags))
const languages = computed(() => props.performer.languages ?? [])

const languageList = computed(() => languages.value.map(languageLabel).join(', '))

// A seção inteira some quando não há nada a mostrar — senão sobraria um título
// órfão sobre o vazio.
const hasAny = computed(
    () =>
        tagGroups.value.length > 0 ||
        languages.value.length > 0 ||
        props.performer.height_cm != null ||
        !!props.performer.drinks ||
        !!props.performer.smokes,
)
</script>

<template>
    <div v-if="hasAny" class="mt-8 border-t border-frame pt-8 space-y-5">
        <h2 class="text-xs uppercase tracking-widest text-muted">Sobre mim</h2>

        <!-- Grid 2 colunas para os fatos curtos: bebida, fumo, altura, idiomas.
             Cada célula só existe se preenchida. -->
        <dl
            v-if="performer.drinks || performer.smokes || performer.height_cm != null || languages.length"
            class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4"
        >
            <div v-if="performer.drinks" class="space-y-1">
                <dt class="text-xs uppercase tracking-wide text-muted">Bebida</dt>
                <dd class="text-sm text-cream flex items-center gap-2">
                    <span aria-hidden="true">{{ drinkIcon(performer.drinks) }}</span>
                    {{ drinkLabel(performer.drinks) }}
                </dd>
            </div>

            <div v-if="performer.smokes" class="space-y-1">
                <dt class="text-xs uppercase tracking-wide text-muted">Fumo</dt>
                <dd class="text-sm text-cream flex items-center gap-2">
                    <span aria-hidden="true">{{ smokeIcon(performer.smokes) }}</span>
                    {{ smokeLabel(performer.smokes) }}
                </dd>
            </div>

            <div v-if="performer.height_cm != null" class="space-y-1">
                <dt class="text-xs uppercase tracking-wide text-muted">Altura</dt>
                <dd class="text-sm text-cream">{{ performer.height_cm }} cm</dd>
            </div>

            <div v-if="languages.length" class="space-y-1">
                <dt class="text-xs uppercase tracking-wide text-muted">Idiomas</dt>
                <dd class="text-sm text-cream">{{ languageList }}</dd>
            </div>
        </dl>

        <!-- Tags: pílulas agrupadas, mesmo visual da tela de edição, em row
             abaixo do grid. -->
        <div v-if="tagGroups.length" class="space-y-3">
            <div v-for="group in tagGroups" :key="group.key" class="space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">{{ group.label }}</p>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="tag in group.tags"
                        :key="tag.value"
                        class="rounded-full border border-gold/30 bg-surface px-3 py-1.5 text-xs text-gold"
                    >
                        {{ tag.label }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
