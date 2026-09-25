<script setup>
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    enabled: { type: Boolean, default: false },
    code: { type: String, required: true },
    link: { type: String, required: true },
})

// Qual campo acabou de ser copiado ('link' | 'code' | null), para o feedback.
const copied = ref(null)

async function copy(what) {
    const value = what === 'link' ? props.link : props.code
    try {
        await navigator.clipboard.writeText(value)
        copied.value = what
        setTimeout(() => (copied.value = null), 2000)
    } catch (e) {
        // Sem clipboard (contexto inseguro): o usuário seleciona e copia à mão.
    }
}

async function share() {
    if (!navigator.share) {
        copy('link')
        return
    }
    try {
        await navigator.share({
            title: 'Limen',
            text: 'Entre no Limen com o meu código de indicação:',
            url: props.link,
        })
    } catch (e) {
        // Compartilhamento cancelado — sem ação.
    }
}
</script>

<template>
    <AppLayout title="Indique e ganhe">
        <div class="max-w-2xl mx-auto px-4 py-10 space-y-8">
            <div class="space-y-1">
                <h1 class="font-serif text-3xl md:text-4xl text-cream">Indique e ganhe</h1>
                <p class="text-muted text-sm">
                    Compartilhe seu link. Quando quem você indicar se tornar ativo no Limen,
                    vocês dois ganham tokens de bônus.
                </p>
            </div>

            <div v-if="!enabled" class="bg-surface border border-frame rounded-2xl p-6">
                <p class="text-sm text-muted">
                    O programa de indicação ainda não está ativo. Guarde seu código — em breve
                    ele valerá tokens.
                </p>
            </div>

            <div class="bg-surface border border-frame rounded-2xl p-6 space-y-6">
                <!-- Link de indicação -->
                <div>
                    <label class="text-sm font-medium text-cream">Seu link de indicação</label>
                    <div class="mt-2 flex items-stretch gap-2">
                        <input
                            :value="link"
                            readonly
                            class="flex-1 min-w-0 rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream truncate"
                            @focus="$event.target.select()"
                        />
                        <button
                            type="button"
                            class="shrink-0 inline-flex min-h-[44px] items-center gap-1.5 rounded-lg border border-gold text-gold px-4 text-sm hover:bg-gold/10 transition-colors"
                            @click="copy('link')"
                        >
                            <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" aria-hidden="true">
                                <rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.6" />
                                <path d="M5 15V5a2 2 0 0 1 2-2h10" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            {{ copied === 'link' ? 'Copiado!' : 'Copiar' }}
                        </button>
                    </div>
                </div>

                <!-- Código -->
                <div>
                    <label class="text-sm font-medium text-cream">Seu código</label>
                    <div class="mt-2 flex items-stretch gap-2">
                        <div class="flex-1 min-w-0 rounded-lg border border-frame bg-surface-2 px-3 py-2 font-mono text-lg text-gold tracking-wider">
                            {{ code }}
                        </div>
                        <button
                            type="button"
                            class="shrink-0 inline-flex min-h-[44px] items-center gap-1.5 rounded-lg border border-frame text-muted px-4 text-sm hover:border-gold/50 hover:text-cream transition-colors"
                            @click="copy('code')"
                        >
                            {{ copied === 'code' ? 'Copiado!' : 'Copiar' }}
                        </button>
                    </div>
                </div>

                <!-- Compartilhar (usa o share nativo no celular; cai em copiar no desktop) -->
                <button
                    type="button"
                    class="w-full inline-flex min-h-[44px] items-center justify-center gap-2 rounded-lg bg-gold text-background font-medium px-4 py-2 hover:bg-gold-light transition-colors"
                    @click="share"
                >
                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" aria-hidden="true">
                        <path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7" stroke="currentColor" stroke-width="1.6" />
                        <path d="M12 3v13M8 7l4-4 4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Compartilhar
                </button>
            </div>

            <div class="text-xs text-muted space-y-1">
                <p>O bônus é creditado em tokens, para usar dentro do Limen.</p>
                <p>A recompensa chega quando quem você indicou faz o primeiro pagamento (membro) ou tem o primeiro recebimento como performer verificada.</p>
            </div>
        </div>
    </AppLayout>
</template>
