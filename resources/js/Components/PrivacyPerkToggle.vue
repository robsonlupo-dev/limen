<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

/**
 * Toggle de um perk de privacidade Black/FC.
 *
 * Sem o tier o toggle NÃO some: aparece bloqueado, com o convite para assinar.
 * É o que faz o perk existir como argumento de venda — um benefício invisível
 * para quem não o tem não justifica salto de preço nenhum.
 *
 * `inverted` cobre o Read Receipts, onde o estado privado é o campo em `false`:
 * o switch mostra "confirmação de leitura desligada" como LIGADO, porque o que
 * o membro liga é a privacidade, não o campo do banco.
 */
const props = defineProps({
    perk: { type: String, required: true },
    title: { type: String, required: true },
    description: { type: String, required: true },
    detail: { type: String, default: '' },
    inverted: { type: Boolean, default: false },
})

const page = usePage()

const user = computed(() => page.props.auth?.user ?? null)
const privacy = computed(() => user.value?.privacy ?? {})
const canUse = computed(() => privacy.value.eligible === true)

// Valor cru da coluna → posição do switch.
const enabled = computed(() => {
    const raw = privacy.value[props.perk] === true
    return props.inverted ? !raw : raw
})

const tierBadge = computed(() => (user.value?.circle === 'founders_circle' ? 'FC' : 'Black'))

const saving = ref(false)

function toggle() {
    if (saving.value || !canUse.value) return
    saving.value = true

    // Manda o estado desejado, não "inverta": duplo clique ou retry não podem
    // acabar religando o que o membro acabou de desligar.
    const desired = props.inverted ? enabled.value : !enabled.value

    router.patch(
        route('consumer.settings.privacy'),
        { perk: props.perk, enabled: desired },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    )
}
</script>

<template>
    <div class="rounded-xl border border-frame bg-surface p-5 flex items-start justify-between gap-6">
        <div class="space-y-1">
            <div class="flex items-center gap-2">
                <span class="text-cream font-medium">{{ title }}</span>
                <span class="inline-flex items-center rounded-full border border-gold/40 bg-gold/10 px-2 py-0.5 text-[11px] tracking-wide text-gold">
                    {{ canUse ? tierBadge : 'Black' }}
                </span>
            </div>
            <p class="text-muted text-sm">{{ description }}</p>
            <p v-if="detail" class="text-muted text-xs">{{ detail }}</p>
            <Link
                v-if="!canUse"
                :href="route('subscribe.index')"
                class="inline-block pt-1 text-sm text-gold hover:text-gold-light transition-colors"
            >
                Disponível no Black &rarr;
            </Link>
        </div>

        <button
            type="button"
            role="switch"
            :aria-checked="enabled"
            :aria-label="title"
            :disabled="saving || !canUse"
            @click="toggle"
            :class="[
                'relative shrink-0 h-7 w-12 rounded-full border transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-gold focus-visible:outline-offset-2',
                enabled ? 'bg-gold/80 border-gold' : 'bg-surface-2 border-frame',
                (saving || !canUse) && 'opacity-50 cursor-not-allowed',
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
