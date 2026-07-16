<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import Input from '@/Components/Input.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({
    circles: { type: Array, required: true },
    subscription: { type: Object, default: null },
})

// Benefícios principais por Círculo (resumo curado — CIRCLES_SYSTEM_V4.md).
const BENEFITS = {
    explorador: ['Chat livre com performers', 'Feed + lives públicas', 'Recebe Interesse Controlado', 'Badge prata'],
    insider: ['Prioridade no Interesse', '10% off em lives privadas', 'Área Bastidores', 'Badge dourado'],
    prestige: ['1 live privada/mês', 'Mensagem direta sem desbloqueio', 'Sala Prestige', 'Modo Discrição básico'],
    black: ['Performer Dedicada 1x/mês', 'Acesso Exclusive & Maison', 'Modo Discrição Absoluto', 'Número BLACK'],
    founders_circle: ['Voto no roadmap', 'FC Sessions & Collection', 'Marcos físicos exclusivos', 'Número FC (1–9999)'],
}

const currentSlug = computed(() => props.subscription?.circle ?? null)
const hasSubscription = computed(() => props.subscription !== null)

const showCardForm = ref(false)
const selectedCircle = ref(null)

const form = useForm({
    circle_slug: '',
    card_holder: '',
    card_number: '',
    card_expiry_month: '',
    card_expiry_year: '',
    card_cvv: '',
})

function openSubscribe(circle) {
    selectedCircle.value = circle
    form.reset()
    form.clearErrors()
    form.circle_slug = circle.slug
    showCardForm.value = true
}

function closeCardForm() {
    showCardForm.value = false
    // Nunca deixa dado de cartão pendurado em memória após fechar.
    form.reset()
}

function submit() {
    form.transform((data) => data).post(route('subscribe.store'), {
        preserveScroll: true,
        // Sucesso redireciona para /painel; o componente desmonta. Em erro,
        // limpa os campos sensíveis (número/CVV) e mantém o modal para correção.
        onError: () => {
            form.card_number = ''
            form.card_cvv = ''
        },
    })
}

const cancelForm = useForm({})
function cancelSubscription() {
    cancelForm.post(route('subscribe.cancel'), { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Círculos">
        <div class="max-w-6xl mx-auto px-6 py-10">
            <header class="text-center mb-10 space-y-2">
                <h1 class="font-serif text-4xl text-cream">Círculos Limen</h1>
                <p class="text-muted text-sm max-w-2xl mx-auto">
                    Assine um Círculo para receber tokens todo mês, desconto nos pacotes e benefícios
                    exclusivos. A assinatura não substitui tokens — reduz o atrito e o custo.
                </p>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div
                    v-for="circle in circles"
                    :key="circle.slug"
                    class="rounded-2xl border p-6 flex flex-col"
                    :class="currentSlug === circle.slug
                        ? 'border-gold bg-gold/5 shadow-[0_0_30px_-10px_rgba(201,162,75,0.4)]'
                        : 'border-frame bg-surface'"
                >
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-serif text-2xl text-cream">{{ circle.name }}</h2>
                        <span
                            v-if="currentSlug === circle.slug"
                            class="shrink-0 rounded-full bg-gold/20 border border-gold/40 px-3 py-1 text-xs text-gold"
                        >
                            Seu plano atual
                        </span>
                    </div>

                    <p class="mt-3">
                        <span class="font-serif text-3xl text-gold">{{ circle.price_formatted }}</span>
                        <span class="text-muted text-sm">/mês</span>
                    </p>

                    <ul class="mt-4 space-y-1.5 text-sm text-muted">
                        <li class="text-cream">✦ {{ circle.monthly_tokens }} tokens/mês inclusos</li>
                        <li>−{{ circle.discount_pct }}% em pacotes de tokens</li>
                        <li v-for="benefit in BENEFITS[circle.slug] ?? []" :key="benefit">
                            · {{ benefit }}
                        </li>
                    </ul>

                    <div class="mt-6 pt-4 border-t border-frame/50">
                        <!-- Plano atual: mostrar estado + cancelar -->
                        <template v-if="currentSlug === circle.slug">
                            <p v-if="subscription.cancel_at_period_end" class="text-xs text-muted">
                                Encerra em {{ subscription.current_period_end }}.
                            </p>
                            <template v-else>
                                <p class="text-xs text-muted mb-3">Renova em {{ subscription.current_period_end }}.</p>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :loading="cancelForm.processing"
                                    @click="cancelSubscription"
                                >
                                    Cancelar assinatura
                                </Button>
                            </template>
                        </template>

                        <!-- Por convite (Founders Circle) -->
                        <Button v-else-if="circle.invite_only" variant="ghost" size="sm" disabled>
                            Apenas por convite
                        </Button>

                        <!-- Usuário já tem outro Círculo ativo -->
                        <p v-else-if="hasSubscription" class="text-xs text-muted">
                            Cancele o Círculo atual para trocar.
                        </p>

                        <!-- Sem assinatura: pode assinar -->
                        <Button v-else variant="primary" size="sm" @click="openSubscribe(circle)">
                            Assinar
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulário de cartão -->
        <Modal :show="showCardForm" max-width="md" @close="closeCardForm">
            <form v-if="selectedCircle" class="space-y-5" @submit.prevent="submit">
                <div>
                    <h3 class="font-serif text-2xl text-cream">Assinar {{ selectedCircle.name }}</h3>
                    <p class="text-sm text-muted mt-1">
                        {{ selectedCircle.price_formatted }}/mês · {{ selectedCircle.monthly_tokens }} tokens no ato.
                    </p>
                </div>

                <Input
                    id="card_holder"
                    v-model="form.card_holder"
                    label="Nome no cartão"
                    autocomplete="cc-name"
                    placeholder="Como está impresso no cartão"
                    :error="form.errors.card_holder"
                    required
                />

                <Input
                    id="card_number"
                    v-model="form.card_number"
                    label="Número do cartão"
                    autocomplete="cc-number"
                    placeholder="0000 0000 0000 0000"
                    :error="form.errors.card_number"
                    required
                />

                <div class="grid grid-cols-3 gap-3">
                    <Input
                        id="card_expiry_month"
                        v-model="form.card_expiry_month"
                        label="Mês"
                        autocomplete="cc-exp-month"
                        placeholder="MM"
                        :error="form.errors.card_expiry_month"
                        required
                    />
                    <Input
                        id="card_expiry_year"
                        v-model="form.card_expiry_year"
                        label="Ano"
                        autocomplete="cc-exp-year"
                        placeholder="AAAA"
                        :error="form.errors.card_expiry_year"
                        required
                    />
                    <Input
                        id="card_cvv"
                        v-model="form.card_cvv"
                        label="CVV"
                        autocomplete="cc-csc"
                        placeholder="000"
                        :error="form.errors.card_cvv"
                        required
                    />
                </div>

                <p class="text-xs text-muted">
                    🔒 Seus dados de cartão vão direto ao processador de pagamento. O Limen não
                    armazena o número do seu cartão.
                </p>

                <div class="flex justify-end gap-3 pt-2">
                    <Button type="button" variant="ghost" :disabled="form.processing" @click="closeCardForm">
                        Cancelar
                    </Button>
                    <Button type="submit" variant="primary" :loading="form.processing">
                        Assinar por {{ selectedCircle.price_formatted }}/mês
                    </Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
