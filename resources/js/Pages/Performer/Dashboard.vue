<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import KycPendingBanner from '@/Components/KycPendingBanner.vue'

const props = defineProps({
    wallet: { type: Number, required: true },
    totalEarned: { type: Number, required: true },
    tips: { type: Array, required: true },
    // Faixa ("Menos de 5", "10+", ou o número exato a partir de 500), não Number:
    // o contador preciso de um perfil pequeno identifica quem seguiu e quando.
    followers: { type: String, required: true },
    kycStatus: { type: String, required: true },
    isLive: { type: Boolean, required: true },
    // Visitantes já pseudonimizados (FanAlias) pelo servidor — o id do membro
    // não chega aqui, como nas gorjetas.
    visitors: { type: Array, default: () => [] },
    // Falso enquanto o Piso de Anonimato não destravar. Não é "lista vazia":
    // vazio e escondido são estados diferentes, e a tela explica cada um.
    visitorsVisible: { type: Boolean, default: false },
    visitorsWindowHours: { type: Number, default: 24 },
    anonymityFloor: { type: Number, default: 5 },
})

const kycBadge = computed(() => {
    return {
        pending: { label: 'Pendente', class: 'bg-gold/10 text-gold border-gold/30' },
        active: { label: 'Verificado', class: 'bg-success/10 text-success border-success/30' },
        rejected: { label: 'Rejeitado', class: 'bg-danger/10 text-danger border-danger/30' },
    }[props.kycStatus] ?? { label: props.kycStatus, class: 'bg-muted/10 text-muted border-frame' }
})

const canGoLive = computed(() => props.kycStatus === 'active')
</script>

<template>
    <AppLayout title="Painel do performer">
        <!-- Sprint 7: enquanto o KYC não está aprovado o perfil não existe no
             catálogo — o banner fica até o status virar aprovado, sem fechar. -->
        <KycPendingBanner :kyc-status="kycStatus" />

        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Painel</h1>
                    <p class="text-muted text-sm">Visão geral dos seus ganhos e atividade.</p>
                </div>
                <Button
                    variant="primary"
                    :disabled="!canGoLive"
                    :title="!canGoLive ? 'Disponível somente após verificação KYC aprovada' : undefined"
                >
                    Ir ao vivo
                </Button>
            </div>

            <!-- Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <Link
                    :href="route('performer.payouts.index')"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-1 block no-underline hover:border-gold/40 transition-colors"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Saldo</p>
                    <p class="font-serif text-3xl text-gold">{{ wallet }}</p>
                    <p class="text-xs text-gold/70">Sacar &rarr;</p>
                </Link>

                <div class="rounded-xl border border-frame bg-surface p-5 space-y-1">
                    <p class="text-xs text-muted uppercase tracking-wide">Total ganho</p>
                    <p class="font-serif text-3xl text-gold">{{ totalEarned }}</p>
                    <p class="text-xs text-muted">tokens</p>
                </div>

                <Link
                    :href="route('performer.followers')"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-1 block no-underline hover:border-gold/40 transition-colors"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Seguidores</p>
                    <p class="font-serif text-3xl text-cream">{{ followers }}</p>
                    <p class="text-xs text-gold/70">Demonstrar interesse &rarr;</p>
                </Link>

                <div class="rounded-xl border border-frame bg-surface p-5 space-y-2">
                    <p class="text-xs text-muted uppercase tracking-wide">Status KYC</p>
                    <span
                        class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium"
                        :class="kycBadge.class"
                    >
                        {{ kycBadge.label }}
                    </span>
                </div>
            </div>

            <!-- Tips table -->
            <div class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Últimas gorjetas</h2>

                <div v-if="tips.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                    Seus primeiros apoiadores estão a um post de distância.
                </div>

                <div v-else class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Fã</th>
                                <th class="px-5 py-3 font-medium">Tokens</th>
                                <th class="px-5 py-3 font-medium">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(tip, i) in tips"
                                :key="i"
                                class="border-b border-frame/50 last:border-b-0"
                            >
                                <td class="px-5 py-3 text-cream">{{ tip.fan }}</td>
                                <td class="px-5 py-3 text-gold">{{ tip.amount }}</td>
                                <td class="px-5 py-3 text-muted">{{ tip.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Visitantes recentes -->
            <div class="space-y-3">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="font-serif text-xl text-cream">Visitantes recentes</h2>
                    <span class="text-xs text-muted">últimas {{ visitorsWindowHours }}h</span>
                </div>

                <!-- Piso de Anonimato: mesma regra da tela de seguidores. A tela
                     diz POR QUE está vazia — sem isso a performer lê como
                     "ninguém veio" e conclui coisa errada sobre o próprio perfil. -->
                <div v-if="!visitorsVisible" class="rounded-xl border border-frame bg-surface p-8 text-center space-y-1">
                    <p class="text-cream text-sm">Ainda não é possível mostrar os visitantes</p>
                    <p class="text-muted text-xs">
                        Para preservar o anonimato de quem visita, a lista aparece a partir de
                        {{ anonymityFloor }} visitantes — e depende do mesmo piso da sua lista de seguidores.
                    </p>
                </div>

                <!-- Copy DELIBERADAMENTE ambígua: este estado cobre tanto "não
                     houve visita" quanto "houve, mas nenhuma faixa reuniu
                     visitantes suficientes ainda" (k-anonimato). Distinguir os
                     dois casos devolveria à performer exatamente o sinal que o k
                     remove — ela saberia que ALGUÉM passou. Não afirmar zero. -->
                <div v-else-if="visitors.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                    Nada a mostrar nas últimas {{ visitorsWindowHours }} horas.
                </div>

                <div v-else class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Fã</th>
                                <th class="px-5 py-3 font-medium">Visita</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(visit, i) in visitors"
                                :key="i"
                                class="border-b border-frame/50 last:border-b-0"
                            >
                                <td class="px-5 py-3 text-cream">{{ visit.fan }}</td>
                                <!-- Faixa do dia, nunca relógio: horário exato deixava
                                     a performer casar um envio de link com o alias que
                                     aparece logo depois. Ver ProfileVisitService::slot(). -->
                                <td class="px-5 py-3 text-muted">{{ visit.visited_slot }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Nem toda visita aparece aqui, e a tela diz isso: sem o aviso,
                     a lista vazia se lê como "ninguém veio", e a performer tira
                     conclusão de um dado que nunca foi completo. -->
                <p v-if="visitorsVisible" class="text-xs text-muted">
                    Membros com Ghost Mode navegam sem registrar visita — esta lista é parcial por
                    definição.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
