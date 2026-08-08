<script setup>
import { computed, reactive } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { playNotificationSound } from '@/lib/notificationSound'

/**
 * Seção "Notificações" (Sprint 16): três toggles de som — mensagem de chat,
 * gorjeta/presente e chamada ao vivo —, todos ON por padrão. Reusada na tela de
 * Configurações do membro e no painel da performer (por isso role-neutra, como
 * o endpoint). Lê o estado efetivo já resolvido pelo backend
 * (auth.user.notification_preferences, "ausente = ON") e salva um toggle por vez.
 *
 * Ao LIGAR um som, toca uma prévia: confirma para o usuário como ele soa e, de
 * quebra, o próprio clique é o gesto que destrava o áudio no browser.
 */
const page = usePage()

const ITEMS = [
    {
        key: 'message',
        title: 'Som de mensagem',
        description: 'Toca ao receber uma nova mensagem no chat.',
    },
    {
        key: 'tip',
        title: 'Som de gorjeta e presente',
        description: 'Toca quando você recebe uma gorjeta ou presente durante a live.',
    },
    {
        key: 'live',
        title: 'Som de chamada ao vivo',
        description: 'Toca ao receber um pedido de chamada privada.',
    },
]

const prefs = computed(() => page.props.auth?.user?.notification_preferences ?? {})

// Ausente/indefinido conta como ON — mesmo default do backend.
function isOn(key) {
    return prefs.value[key] !== false
}

const saving = reactive({})

function toggle(key) {
    if (saving[key]) return
    const desired = !isOn(key)
    saving[key] = true

    // Prévia só ao LIGAR (e é um gesto de usuário, então destrava o áudio).
    if (desired) playNotificationSound(key)

    router.patch(
        route('notifications.sound.update'),
        { key, enabled: desired },
        { preserveScroll: true, onFinish: () => (saving[key] = false) },
    )
}
</script>

<template>
    <section class="space-y-3">
        <div class="space-y-1">
            <h2 class="font-serif text-2xl text-cream">Notificações</h2>
            <p class="text-muted text-sm">
                Sons discretos para avisos em tempo real. Desligados aqui, ficam em silêncio.
            </p>
        </div>

        <div
            v-for="item in ITEMS"
            :key="item.key"
            class="rounded-xl border border-frame bg-surface p-5 flex items-start justify-between gap-6"
        >
            <div class="space-y-1">
                <span class="text-cream font-medium">{{ item.title }}</span>
                <p class="text-muted text-sm">{{ item.description }}</p>
            </div>

            <button
                type="button"
                role="switch"
                :aria-checked="isOn(item.key)"
                :aria-label="item.title"
                :disabled="saving[item.key]"
                @click="toggle(item.key)"
                :class="[
                    'relative shrink-0 h-7 w-12 rounded-full border transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-gold focus-visible:outline-offset-2',
                    isOn(item.key) ? 'bg-gold/80 border-gold' : 'bg-surface-2 border-frame',
                    saving[item.key] && 'opacity-50 cursor-not-allowed',
                ]"
            >
                <span
                    :class="[
                        'absolute top-1/2 -translate-y-1/2 h-5 w-5 rounded-full bg-cream transition-all duration-200',
                        isOn(item.key) ? 'left-6' : 'left-0.5',
                    ]"
                />
            </button>
        </div>
    </section>
</template>
