<script setup>
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import ReportModal from '@/Components/ReportModal.vue'
import { deleteJson, postJson } from '@/lib/http'

// Pessoas › Solicitações (reorg do Painel, UAT Fase 21): o que é "gente" e pede
// ação dela — pedidos de acesso às fotos privadas (no topo, com contagem),
// fotos recebidas e visitantes recentes. Tudo movido do fim do Resumo, com a
// MESMA lógica e as mesmas regras de privacidade (FanAlias, faixa, Piso).
const props = defineProps({
    // { photo_id, photo_url, fan, member_handle } — nunca o id do membro.
    photoAccessRequests: { type: Array, default: () => [] },
    // Fotos que membros compartilharam: FanAlias + faixa de tempo, nunca id nem relógio.
    receivedPhotos: { type: Array, default: () => [] },
    visitors: { type: Array, default: () => [] },
    visitorsVisible: { type: Boolean, default: false },
    visitorsWindowHours: { type: Number, default: 24 },
    anonymityFloor: { type: Number, default: 5 },
    canSendVisitorInterest: { type: Boolean, default: false },
    visitorInterestRemaining: { type: Number, default: 0 },
})

const toastMessage = ref('')
function toast(msg, ms = 4000) {
    toastMessage.value = msg
    setTimeout(() => (toastMessage.value = ''), ms)
}

// ── Solicitações de acesso (Sprint 13). Cada ação devolve a fila atualizada. ──
const accessRequests = ref([...props.photoAccessRequests])
const decidingKey = ref(null)

function requestKey(req) {
    return `${req.photo_id}:${req.member_handle}`
}

async function decideAccess(req, approve) {
    const key = requestKey(req)
    if (decidingKey.value !== null) return
    decidingKey.value = key
    try {
        const data = approve
            ? await postJson(route('performer.gallery.grant', [req.photo_id, req.member_handle]))
            : await deleteJson(route('performer.gallery.revoke', [req.photo_id, req.member_handle]))
        accessRequests.value = data.requests
        toast(approve ? 'Acesso concedido' : 'Pedido recusado')
    } catch (error) {
        toast(error.status === 429 ? 'Muitas ações em pouco tempo. Aguarde um instante.' : 'Não foi possível concluir. Tente novamente.')
    } finally {
        decidingKey.value = null
    }
}

// ── Fotos recebidas (Sprint 9B): overlay inline + denúncia. ──
const openPhoto = ref(null)
const reporting = ref(null)

// ── Visitantes (Sprint 9): interesse pelo member_handle, nunca id. ──
const visitorQuota = ref(props.visitorInterestRemaining)
const justSent = ref({})
const sendingHandle = ref(null)
const errorFor = ref({})

function canSendTo(visit) {
    return (
        props.canSendVisitorInterest &&
        visitorQuota.value > 0 &&
        !justSent.value[visit.member_handle] &&
        sendingHandle.value === null
    )
}

async function sendVisitorInterest(visit) {
    if (!canSendTo(visit)) return
    errorFor.value = { ...errorFor.value, [visit.member_handle]: '' }
    sendingHandle.value = visit.member_handle
    try {
        await postJson(route('performer.interests.send-visitor'), { member_handle: visit.member_handle })
        justSent.value[visit.member_handle] = true
        visitorQuota.value = Math.max(0, visitorQuota.value - 1)
        toast('Interesse enviado')
    } catch (error) {
        // 404 não é "erro": o alvo pode ter saído do painel (janela, k-anonimato,
        // conta encerrada). Copy única de propósito — distinguir devolveria o sinal.
        const message =
            error.status === 429
                ? 'Muitos envios em pouco tempo. Aguarde um instante.'
                : error.status === 404
                  ? 'Este visitante não está mais disponível no painel.'
                  : (error.data?.message ?? 'Não foi possível enviar. Tente novamente.')
        errorFor.value = { ...errorFor.value, [visit.member_handle]: message }
    } finally {
        sendingHandle.value = null
    }
}
</script>

<template>
    <AppLayout title="Solicitações">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-10">
            <div class="space-y-1">
                <h1 class="font-serif text-4xl text-cream">Solicitações</h1>
                <p class="text-muted text-sm">Quem pediu, quem compartilhou, quem visitou. Sempre por apelido, nunca uma lista de nomes.</p>
            </div>

            <p v-if="toastMessage" class="text-xs text-success">{{ toastMessage }}</p>

            <!-- Solicitações de acesso — no TOPO, com contagem: exige ação. -->
            <section class="space-y-4">
                <div class="flex items-center gap-3">
                    <h2 class="font-serif text-2xl text-cream">Solicitações de acesso</h2>
                    <span
                        v-if="accessRequests.length"
                        class="inline-flex min-w-[1.5rem] items-center justify-center rounded-full bg-gold px-2 py-0.5 text-xs font-semibold text-background"
                    >
                        {{ accessRequests.length }}
                    </span>
                    <span class="text-xs text-muted">{{ accessRequests.length === 1 ? 'pendente' : 'pendentes' }}</span>
                </div>

                <p v-if="!accessRequests.length" class="rounded-xl border border-frame bg-surface p-6 text-sm text-muted">
                    Nenhum pedido de acesso às suas fotos privadas no momento.
                </p>

                <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="req in accessRequests"
                        :key="requestKey(req)"
                        class="flex items-center gap-3 rounded-xl border border-gold/30 bg-surface p-4"
                    >
                        <img :src="req.photo_url" alt="Foto privada" class="h-14 w-14 shrink-0 rounded-lg border border-frame object-cover" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-cream">{{ req.fan }}</p>
                            <p class="text-xs text-muted">Quer ver esta foto</p>
                            <div class="mt-2 flex items-center gap-2">
                                <button
                                    type="button"
                                    :disabled="decidingKey !== null"
                                    class="rounded-md bg-gold px-3 py-1.5 text-xs text-background transition-colors hover:bg-gold-light disabled:opacity-50"
                                    @click="decideAccess(req, true)"
                                >
                                    Aprovar
                                </button>
                                <button
                                    type="button"
                                    :disabled="decidingKey !== null"
                                    class="rounded-md border border-frame px-3 py-1.5 text-xs text-muted transition-colors hover:text-cream hover:border-danger/50 disabled:opacity-50"
                                    @click="decideAccess(req, false)"
                                >
                                    Recusar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Fotos recebidas (Sprint 9B) -->
            <section class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-serif text-2xl text-cream">Fotos recebidas</h2>
                    <span class="text-xs text-muted">{{ receivedPhotos.length }} ativa(s)</span>
                </div>

                <p v-if="!receivedPhotos.length" class="rounded-xl border border-frame bg-surface p-6 text-sm text-muted">
                    Nenhuma foto compartilhada com você no momento.
                </p>

                <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <button
                        v-for="photo in receivedPhotos"
                        :key="photo.access_id"
                        type="button"
                        class="rounded-xl border border-frame bg-surface p-4 text-left hover:border-gold/40 transition-colors"
                        @click="openPhoto = photo"
                    >
                        <p class="text-sm text-cream">{{ photo.fan }}</p>
                        <p class="text-xs text-muted">{{ photo.expires_slot }}</p>
                        <p class="pt-2 text-xs text-gold/70">Ver foto</p>
                    </button>
                </div>

                <p v-if="receivedPhotos.length" class="text-xs text-muted">
                    As fotos somem do servidor quando o prazo acaba. Guardar, reproduzir ou
                    repassar o conteúdo é proibido pelas regras da plataforma e pode levar à
                    suspensão da conta.
                </p>
            </section>

            <!-- Visitantes recentes (Sprint 9), sob o Piso de Anonimato. -->
            <section class="space-y-3">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="font-serif text-2xl text-cream">Visitantes recentes</h2>
                    <span class="text-xs text-muted">últimas {{ visitorsWindowHours }}h</span>
                </div>

                <div v-if="!visitorsVisible" class="rounded-xl border border-frame bg-surface p-8 text-center space-y-1">
                    <p class="text-cream text-sm">Ainda não é possível mostrar os visitantes</p>
                    <p class="text-muted text-xs">
                        Para preservar o anonimato de quem visita, a lista aparece a partir de
                        {{ anonymityFloor }} visitantes — e depende do mesmo piso da sua lista de seguidores.
                    </p>
                </div>

                <div v-else-if="visitors.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                    Nada a mostrar nas últimas {{ visitorsWindowHours }} horas.
                </div>

                <div v-else class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Fã</th>
                                <th class="px-5 py-3 font-medium">Visita</th>
                                <th v-if="canSendVisitorInterest" class="px-5 py-3 font-medium text-right">Interesse</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(visit, i) in visitors" :key="i" class="border-b border-frame/50 last:border-b-0">
                                <td class="px-5 py-3 text-cream">
                                    {{ visit.fan }}<span v-if="visit.lifestyle" class="text-muted"> · {{ visit.lifestyle }}</span>
                                </td>
                                <td class="px-5 py-3 text-muted">{{ visit.visited_slot }}</td>
                                <td v-if="canSendVisitorInterest" class="px-5 py-3 text-right">
                                    <span v-if="justSent[visit.member_handle]" class="text-xs text-success">Enviado</span>
                                    <template v-else>
                                        <button
                                            type="button"
                                            :disabled="!canSendTo(visit)"
                                            class="rounded-lg border border-gold px-3 py-1.5 text-xs text-gold hover:bg-gold/10 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                            @click="sendVisitorInterest(visit)"
                                        >
                                            {{ sendingHandle === visit.member_handle ? 'Enviando...' : 'Demonstrar' }}
                                        </button>
                                        <p v-if="errorFor[visit.member_handle]" class="pt-1 text-xs text-danger">{{ errorFor[visit.member_handle] }}</p>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="visitorsVisible && canSendVisitorInterest" class="text-xs text-muted">
                    Você pode demonstrar interesse para {{ visitorQuota }}
                    {{ visitorQuota === 1 ? 'visitante' : 'visitantes' }} hoje. O sinal não leva
                    texto — quem recebe decide se quer revelar quem é você.
                </p>
                <p v-if="visitorsVisible" class="text-xs text-muted">
                    Membros com Ghost Mode navegam sem registrar visita — esta lista é parcial por definição.
                </p>
            </section>
        </div>

        <!-- Visualização inline da foto recebida (inline + no-store no endpoint). -->
        <div v-if="openPhoto" class="fixed inset-0 z-50 flex items-center justify-center bg-background/95 p-6" @click="openPhoto = null">
            <div class="max-w-lg space-y-3 text-center" @click.stop>
                <img :src="route('performer.photos.image', openPhoto.access_id)" alt="Foto recebida" class="max-h-[70vh] w-auto rounded-xl border border-frame" />
                <p class="text-sm text-cream">{{ openPhoto.fan }} · {{ openPhoto.expires_slot }}</p>
                <div class="flex items-center justify-center gap-4">
                    <button class="text-xs text-muted hover:text-cream" @click="openPhoto = null">Fechar</button>
                    <!-- access_id, NUNCA o id da foto (correlacionável entre perfis). -->
                    <button class="text-xs text-danger hover:underline" @click="reporting = openPhoto">Denunciar foto</button>
                </div>
            </div>
        </div>

        <ReportModal
            :show="reporting !== null"
            reportable-type="member_photo"
            :reportable-id="reporting?.access_id ?? 0"
            @close="reporting = null"
        />
    </AppLayout>
</template>
