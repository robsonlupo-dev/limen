<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

/**
 * Toggle "Visível para performers" (Sprint 16). Controla se o membro aparece no
 * catálogo de membros que as performers navegam para sinalizar interesse.
 *
 * O estado vem já RESOLVIDO do backend (auth.user.visible_to_performers =
 * User::isVisibleToPerformers, com o padrão-por-tier aplicado quando o membro
 * nunca escolheu). Ao clicar, grava a escolha explícita — a partir daí o
 * tri-state vira um booleano fixo para este membro.
 */
const page = usePage()

const enabled = computed(() => page.props.auth?.user?.visible_to_performers === true)

const saving = ref(false)

function toggle() {
    if (saving.value) return
    saving.value = true

    router.patch(
        route('consumer.settings.performer-visibility'),
        { enabled: !enabled.value },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    )
}
</script>

<template>
    <div class="rounded-xl border border-frame bg-surface p-5 flex items-start justify-between gap-6">
        <div class="space-y-1">
            <span class="text-cream font-medium">Visível para performers</span>
            <p class="text-muted text-sm">
                Permite que performers encontrem você no catálogo de membros e demonstrem interesse.
            </p>
            <p class="text-muted text-xs">
                Elas veem apenas um apelido e sua atividade recente — nunca seu nome, e-mail ou plano.
                Você continua decidindo se paga para abrir a conversa.
            </p>
        </div>

        <button
            type="button"
            role="switch"
            :aria-checked="enabled"
            aria-label="Visível para performers"
            :disabled="saving"
            @click="toggle"
            :class="[
                'relative shrink-0 h-7 w-12 rounded-full border transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-gold focus-visible:outline-offset-2',
                enabled ? 'bg-gold/80 border-gold' : 'bg-surface-2 border-frame',
                saving && 'opacity-50 cursor-not-allowed',
            ]"
        >
            <span
                :class="[
                    'absolute top-1/2 -translate-y-1/2 h-5 w-5 rounded-full bg-cream transition-all duration-200',
                    enabled ? 'left-6' : 'left-0.5',
                ]"
            />
        </button>
    </div>
</template>
