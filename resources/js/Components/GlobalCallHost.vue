<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import CallIncoming from '@/Components/CallIncoming.vue'
import PrivateCall from '@/Components/PrivateCall.vue'

/**
 * Host GLOBAL da chamada 1:1 — lado da PERFORMER, FORA da live
 * (feat/online-presence-call). Montado no AppLayout: a performer LOGADA recebe o
 * pedido de chamada em QUALQUER tela (o <CallIncoming> escuta o canal pessoal
 * user.{id}) e, ao aceitar, a <PrivateCall> abre numa sobreposição em tela cheia.
 *
 * INVISIBILIDADE (invariante de privacidade): a chamada 1:1 NÃO liga `is_live` —
 * o aceite (CallService::accept) só cria a sala LiveKit e cobra o 1º minuto, nunca
 * marca a performer como "ao vivo". Logo ninguém no catálogo/trilha "Agora" sabe
 * que ela está em chamada; para o resto do mundo ela segue apenas "online".
 *
 * NÃO duplicar o ouvinte: DENTRO do console da live (página `Performer/Live`) quem
 * hospeda a chamada é o <LiveRoom> (que PAUSA a live ao aceitar). Lá o <CallIncoming>
 * já está montado no mesmo canal user.{id}; por isso este host se DESLIGA naquela
 * página — senão haveria dois cards "Chamada recebida" no mesmo canal.
 */
const page = usePage()

const myUserId = computed(() => page.props.auth?.user?.id ?? 0)
const isActivePerformer = computed(() =>
    page.props.auth?.user?.role === 'performer' && page.props.auth?.user?.status === 'active',
)
const callEnabled = computed(() => !!page.props.features?.call_enabled)

// Desliga no console da live (o LiveRoom cuida da chamada lá, pausando a live).
const onLiveConsole = computed(() => page.component === 'Performer/Live')

const active = computed(
    () => isActivePerformer.value && callEnabled.value && myUserId.value > 0 && !onLiveConsole.value,
)

const activeCall = ref(null) // { callId, token, wsUrl }

function onCallAccepted({ callId, token, wsUrl }) {
    activeCall.value = { callId, token, wsUrl }
}

function onCallEnded() {
    activeCall.value = null
}
</script>

<template>
    <div v-if="active">
        <!-- Chamada 1:1 em andamento: sobrepõe tudo. Sem live por baixo (ela não
             estava transmitindo); ao encerrar, só fecha a sobreposição. -->
        <div v-if="activeCall" class="fixed inset-0 z-[60] bg-black">
            <div class="mx-auto h-full max-w-3xl p-3 sm:p-4">
                <PrivateCall
                    :call-id="activeCall.callId"
                    :token="activeCall.token"
                    :ws-url="activeCall.wsUrl"
                    role="performer"
                    @ended="onCallEnded"
                />
            </div>
        </div>

        <!-- Aviso "Chamada recebida" (card fixo canto inferior direito). Só quando
             NÃO há chamada em andamento, para não reaparecer durante a 1:1. -->
        <CallIncoming v-else :my-user-id="myUserId" @accepted="onCallAccepted" />
    </div>
</template>
