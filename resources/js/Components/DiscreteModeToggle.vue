<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const page = usePage()

const user = computed(() => page.props.auth?.user ?? null)
const canUse = computed(() => user.value?.can_use_discrete_mode === true)
const enabled = computed(() => user.value?.discrete_mode === true)

// Badge do tier que dá o perk. 'FC' é como o Founders Circle aparece na UI.
const tierBadge = computed(() => (user.value?.circle === 'founders_circle' ? 'FC' : 'Black'))

const saving = ref(false)

function toggle() {
    if (saving.value) return
    saving.value = true

    // Manda o estado desejado, não "inverta": duplo clique ou retry não podem
    // acabar desligando o modo sem querer.
    router.patch(
        route('consumer.settings.discrete-mode'),
        { discrete_mode: !enabled.value },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = false),
        },
    )
}
</script>

<template>
    <div v-if="canUse" class="rounded-xl border border-frame bg-surface p-5 flex items-start justify-between gap-6">
        <div class="space-y-1">
            <div class="flex items-center gap-2">
                <span class="text-cream font-medium">Modo Discreto</span>
                <span class="inline-flex items-center rounded-full border border-gold/40 bg-gold/10 px-2 py-0.5 text-[11px] tracking-wide text-gold">
                    {{ tierBadge }}
                </span>
            </div>
            <p class="text-muted text-sm">Sua presença fica invisível para performers</p>
            <p class="text-muted text-xs">
                Você continua seguindo normalmente — apenas não aparece na lista de seguidores delas.
            </p>
        </div>

        <button
            type="button"
            role="switch"
            :aria-checked="enabled"
            aria-label="Modo Discreto"
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
