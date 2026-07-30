<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import KycPendingBanner from '@/Components/KycPendingBanner.vue'
import ReportModal from '@/Components/ReportModal.vue'
import StoryPanel from '@/Components/StoryPanel.vue'
import { postJson } from '@/lib/http'

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
    // Só performer verificada envia interesse pelo painel (regra do PO). A tela
    // esconde o botão; quem recusa de fato é o Form Request.
    canSendVisitorInterest: { type: Boolean, default: false },
    visitorInterestRemaining: { type: Number, default: 0 },
    // Fotos que membros compartilharam com ela. Já chegam pseudonimizadas
    // (FanAlias) e com a faixa de tempo — nunca o id do membro nem relógio.
    receivedPhotos: { type: Array, default: () => [] },
    // Stories vivos dela. `view_count` já vem em FAIXA (ou null no exclusivo) —
    // o número cru não trafega nas props, que é o caminho do DevTools.
    stories: { type: Array, default: () => [] },
    storyVisibilityLevels: { type: Array, default: () => [] },
    canPublishStories: { type: Boolean, default: false },
})

// A foto abre em overlay, servida inline pelo endpoint. Não há botão de baixar:
// o download não é impedível (nenhum cabeçalho impede), mas a tela não o
// oferece — e a copy não promete o que o TTL não entrega.
const openPhoto = ref(null)

// A foto que está sendo denunciada (o modal lê o access_id dela).
const reporting = ref(null)

// Interesse a partir do painel de visitantes. Mesmo fluxo do botão da tela de
// Seguidores: manda o member_handle (16 hex do FanAlias), nunca um id.
const visitorQuota = ref(props.visitorInterestRemaining)
const justSent = ref({})
const sendingHandle = ref(null)
const errorFor = ref({})
const toastMessage = ref('')

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
        await postJson(route('performer.interests.send-visitor'), {
            member_handle: visit.member_handle,
        })

        justSent.value[visit.member_handle] = true
        visitorQuota.value = Math.max(0, visitorQuota.value - 1)
        toastMessage.value = 'Interesse enviado'
        setTimeout(() => (toastMessage.value = ''), 4000)
    } catch (error) {
        // 404 aqui NÃO significa "erro do sistema": o alvo pode ter saído do
        // painel entre o render e o clique (janela de 24h, k-anonimato, conta
        // encerrada). A copy é deliberadamente a mesma para todos os casos —
        // distinguir "não existe" de "existe mas saiu da lista" devolveria à
        // performer o sinal que os pisos e o k removem.
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
                                <th v-if="canSendVisitorInterest" class="px-5 py-3 font-medium text-right">Interesse</th>
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
                                <td v-if="canSendVisitorInterest" class="px-5 py-3 text-right">
                                    <span v-if="justSent[visit.member_handle]" class="text-xs text-success">
                                        Enviado
                                    </span>
                                    <template v-else>
                                        <button
                                            type="button"
                                            :disabled="!canSendTo(visit)"
                                            class="rounded-lg border border-gold px-3 py-1.5 text-xs text-gold hover:bg-gold/10 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                            @click="sendVisitorInterest(visit)"
                                        >
                                            {{ sendingHandle === visit.member_handle ? 'Enviando...' : 'Demonstrar' }}
                                        </button>
                                        <p v-if="errorFor[visit.member_handle]" class="pt-1 text-xs text-danger">
                                            {{ errorFor[visit.member_handle] }}
                                        </p>
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

                <p v-if="toastMessage" class="text-xs text-success">{{ toastMessage }}</p>

                <!-- Nem toda visita aparece aqui, e a tela diz isso: sem o aviso,
                     a lista vazia se lê como "ninguém veio", e a performer tira
                     conclusão de um dado que nunca foi completo. -->
                <p v-if="visitorsVisible" class="text-xs text-muted">
                    Membros com Ghost Mode navegam sem registrar visita — esta lista é parcial por
                    definição.
                </p>
            </div>

            <!-- Meus Stories (Sprint 9C). A faixa e o `null` do nível exclusivo
                 vêm resolvidos do servidor — o componente não decide nada. Some
                 para a performer ainda em KYC: as rotas exigem
                 `can('performer-active')`, e oferecer o botão seria oferecer 403. -->
            <StoryPanel
                v-if="canPublishStories"
                :stories="stories"
                :visibility-levels="storyVisibilityLevels"
            />

            <!-- Fotos recebidas (Sprint 9B) -->
            <div class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-serif text-2xl text-cream">Fotos recebidas</h2>
                    <span class="text-xs text-muted">{{ receivedPhotos.length }} ativa(s)</span>
                </div>

                <p v-if="!receivedPhotos.length" class="text-sm text-muted">
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

                <!-- Sem instrumento NOMEADO, e isso é decisão, não vagueza.
                     O Contrato de Performance é placeholder (aguardando Opice
                     Blum): afirmar que um comportamento viola cláusula de um
                     texto que ninguém pode ler é a mesma falha de linguagem que
                     o § 1.1 existe para evitar. E "Termos de Uso" seria pior —
                     não há rota para eles neste app (o link do rodapé é `#`),
                     então a atribuição seria a um documento que ela nunca
                     aceitou e não consegue abrir.
                     A regra da plataforma vale por si e é o que a performer de
                     fato aceitou; quando o texto definitivo entrar, esta frase
                     pode voltar a citá-lo pelo nome. -->
                <p v-if="receivedPhotos.length" class="text-xs text-muted">
                    As fotos somem do servidor quando o prazo acaba. Guardar, reproduzir ou
                    repassar o conteúdo é proibido pelas regras da plataforma e pode levar à
                    suspensão da conta.
                </p>
            </div>
        </div>

        <!-- Visualização inline. Sem botão de download e sem link direto: o
             endpoint responde com Content-Disposition: inline e no-store. -->
        <div
            v-if="openPhoto"
            class="fixed inset-0 z-50 flex items-center justify-center bg-background/95 p-6"
            @click="openPhoto = null"
        >
            <div class="max-w-lg space-y-3 text-center" @click.stop>
                <img
                    :src="route('performer.photos.image', openPhoto.access_id)"
                    alt="Foto recebida"
                    class="max-h-[70vh] w-auto rounded-xl border border-frame"
                />
                <p class="text-sm text-cream">{{ openPhoto.fan }} · {{ openPhoto.expires_slot }}</p>
                <div class="flex items-center justify-center gap-4">
                    <button class="text-xs text-muted hover:text-cream" @click="openPhoto = null">Fechar</button>
                    <!-- O handle é o access_id, NUNCA o id da foto: aquele é comum
                         às performers com quem o mesmo membro compartilhou, e
                         trafegá-lo aqui daria um identificador correlacionável
                         entre perfis — o que o FanAlias existe para impedir. -->
                    <button class="text-xs text-danger hover:underline" @click="reporting = openPhoto">
                        Denunciar foto
                    </button>
                </div>
            </div>
        </div>

        <!-- Denúncia da foto recebida. Mesma porta (/reportar) e mesmo modal dos
             outros alvos: o dedup por janela, o lock anti-duplo-submit e a
             resposta uniforme já vivem lá. Denunciar CONGELA o GC e o revoke
             daquela foto — sem isso a denúncia chegaria para um arquivo que o
             titular já apagou. -->
        <ReportModal
            :show="reporting !== null"
            reportable-type="member_photo"
            :reportable-id="reporting?.access_id ?? 0"
            @close="reporting = null"
        />
    </AppLayout>
</template>
