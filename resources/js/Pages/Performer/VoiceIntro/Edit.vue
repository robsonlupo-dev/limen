<script setup>
import { computed, ref, onBeforeUnmount } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import VoiceIntroPlayer from '@/Components/VoiceIntroPlayer.vue'
import { postForm, deleteJson } from '@/lib/http'

const props = defineProps({
    // { status, duration_seconds, reject_reason, audio_url } ou null.
    intro: { type: Object, default: null },
    maxSeconds: { type: Number, default: 20 },
    maxBytes: { type: Number, default: 5 * 1024 * 1024 },
})

const STATUS_LABELS = {
    processing: 'Processando…',
    pending: 'Em análise',
    approved: 'No ar',
    rejected: 'Recusada',
    failed: 'Falhou',
}

const statusLabel = computed(() => (props.intro ? STATUS_LABELS[props.intro.status] : null))

// Estado do envio.
const busy = ref(false)
const error = ref('')
const success = ref('')

// Gravação (MediaRecorder). O blob gravado vira o arquivo enviado; upload de
// arquivo é a alternativa. Os dois desembocam no MESMO envio.
const recording = ref(false)
const elapsed = ref(0)
const recordedBlob = ref(null)
const recordedUrl = ref(null)
let mediaRecorder = null
let mediaStream = null
let chunks = []
let timer = null
let hardStop = null

const canRecord = computed(() => typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && typeof MediaRecorder !== 'undefined')

function resetFeedback() {
    error.value = ''
    success.value = ''
}

async function startRecording() {
    resetFeedback()
    clearRecorded()

    try {
        mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true })
    } catch {
        error.value = 'Não foi possível acessar o microfone. Verifique a permissão do navegador.'
        return
    }

    chunks = []
    mediaRecorder = new MediaRecorder(mediaStream)
    mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data) }
    mediaRecorder.onstop = () => {
        recordedBlob.value = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' })
        recordedUrl.value = URL.createObjectURL(recordedBlob.value)
        stopStream()
    }

    mediaRecorder.start()
    recording.value = true
    elapsed.value = 0
    timer = setInterval(() => { elapsed.value += 1 }, 1000)
    // Corte DURO no cliente (o servidor é a autoridade, mas isto evita mandar
    // 3 min à toa). +1s de folga para o gate do servidor decidir.
    hardStop = setTimeout(() => stopRecording(), (props.maxSeconds + 1) * 1000)
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop()
    }
    recording.value = false
    if (timer) { clearInterval(timer); timer = null }
    if (hardStop) { clearTimeout(hardStop); hardStop = null }
}

function stopStream() {
    if (mediaStream) {
        mediaStream.getTracks().forEach((t) => t.stop())
        mediaStream = null
    }
}

function clearRecorded() {
    if (recordedUrl.value) { URL.revokeObjectURL(recordedUrl.value); recordedUrl.value = null }
    recordedBlob.value = null
}

// Upload de arquivo.
const fileInput = ref(null)
const chosenFile = ref(null)

function onFileChange(e) {
    resetFeedback()
    const file = e.target.files?.[0] ?? null
    if (file && file.size > props.maxBytes) {
        error.value = `O arquivo excede o tamanho máximo de ${Math.round(props.maxBytes / 1024 / 1024)} MB.`
        chosenFile.value = null
        return
    }
    chosenFile.value = file
    clearRecorded()
}

const hasSomethingToSend = computed(() => !!recordedBlob.value || !!chosenFile.value)

async function send() {
    resetFeedback()
    if (!hasSomethingToSend.value || busy.value) return

    const form = new FormData()
    if (recordedBlob.value) {
        // Nome com extensão para o servidor sniffar; o re-encode ffmpeg é quem
        // enforça de verdade o formato.
        form.append('audio', recordedBlob.value, 'apresentacao.webm')
    } else {
        form.append('audio', chosenFile.value)
    }

    busy.value = true
    try {
        const data = await postForm(route('performer.voice-intro.store'), form)
        success.value = data.message ?? 'Áudio recebido.'
        clearRecorded()
        chosenFile.value = null
        if (fileInput.value) fileInput.value.value = ''
        router.reload({ only: ['intro'] })
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível enviar o áudio.'
    } finally {
        busy.value = false
    }
}

async function removeIntro() {
    resetFeedback()
    if (busy.value) return
    busy.value = true
    try {
        await deleteJson(route('performer.voice-intro.destroy'))
        success.value = 'Apresentação de voz removida.'
        router.reload({ only: ['intro'] })
    } catch {
        error.value = 'Não foi possível remover.'
    } finally {
        busy.value = false
    }
}

onBeforeUnmount(() => {
    stopRecording()
    stopStream()
    clearRecorded()
})
</script>

<template>
    <AppLayout title="Apresentação de voz">
        <div class="mx-auto max-w-2xl space-y-8 px-6 py-10">
            <div class="space-y-2">
                <Link :href="route('performer.dashboard')" class="text-sm text-gold/80 no-underline hover:text-gold">&larr; Meu Painel</Link>
                <h1 class="font-serif text-3xl text-cream">Apresentação de voz</h1>
                <p class="text-sm text-muted">
                    Grave uma mensagem curta (até {{ maxSeconds }} segundos) para o seu perfil. É opcional —
                    uma isca para quem visita ficar curioso.
                </p>
            </div>

            <!-- Aviso de consentimento explícito: a voz é identificável, e a análise
                 humana é obrigatória. Isto NÃO fica só nos Termos. -->
            <div class="rounded-xl border border-gold/30 bg-gold/5 p-5 text-sm text-cream/90 space-y-2">
                <p class="font-medium text-gold">Antes de gravar</p>
                <p>Sua voz é <strong>identificável</strong>. Ao publicar, ela fica audível para qualquer visitante do seu perfil.</p>
                <p>Todo áudio passa por <strong>análise</strong> antes de aparecer — só vai ao ar depois de aprovado. Não combine encontros nem passe contato por áudio.</p>
            </div>

            <!-- Estado atual da intro -->
            <div v-if="intro" class="rounded-xl border border-frame bg-surface p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <p class="font-serif text-lg text-cream">Sua apresentação atual</p>
                    <span
                        class="rounded-full px-3 py-1 text-xs font-medium"
                        :class="{
                            'bg-gold/15 text-gold': intro.status === 'approved',
                            'bg-cream/10 text-muted': intro.status === 'processing' || intro.status === 'pending',
                            'bg-danger/15 text-danger': intro.status === 'rejected' || intro.status === 'failed',
                        }"
                    >
                        {{ statusLabel }}
                    </span>
                </div>

                <p v-if="intro.status === 'pending'" class="text-sm text-muted">
                    Recebida e em análise. Ela aparece no seu perfil assim que for aprovada.
                </p>
                <p v-else-if="intro.status === 'processing'" class="text-sm text-muted">
                    Processando o áudio. Atualize em instantes.
                </p>
                <p v-else-if="intro.status === 'approved'" class="text-sm text-muted">
                    No ar no seu perfil.
                </p>
                <p v-else-if="intro.reject_reason" class="text-sm text-danger">
                    {{ intro.reject_reason }}
                </p>

                <div class="flex items-center gap-4 pt-1">
                    <VoiceIntroPlayer
                        v-if="intro.audio_url"
                        :url="intro.audio_url"
                        label="sua apresentação"
                    />
                    <span v-if="intro.duration_seconds" class="text-xs text-muted">{{ intro.duration_seconds }}s</span>
                    <button
                        type="button"
                        class="mi-press text-sm text-danger/80 hover:text-danger"
                        :disabled="busy"
                        @click="removeIntro"
                    >
                        Remover
                    </button>
                </div>
            </div>

            <!-- Gravar / enviar -->
            <div class="rounded-xl border border-frame bg-surface p-5 space-y-5">
                <p class="font-serif text-lg text-cream">{{ intro ? 'Substituir por uma nova' : 'Gravar ou enviar' }}</p>

                <!-- Gravação no navegador -->
                <div v-if="canRecord" class="space-y-3">
                    <div class="flex items-center gap-3">
                        <Button v-if="!recording" variant="ghost" size="sm" @click="startRecording">Gravar</Button>
                        <Button v-else variant="danger" size="sm" @click="stopRecording">Parar</Button>
                        <span v-if="recording" class="text-sm text-gold">
                            <span class="mr-2 inline-block h-2 w-2 rounded-full bg-danger animate-pulse" />
                            {{ elapsed }}s / {{ maxSeconds }}s
                        </span>
                    </div>
                    <div v-if="recordedUrl" class="flex items-center gap-3">
                        <audio :src="recordedUrl" controls class="h-9 w-full max-w-xs" />
                        <button type="button" class="mi-press text-xs text-muted hover:text-cream" @click="clearRecorded">Descartar</button>
                    </div>
                </div>
                <p v-else class="text-xs text-muted">
                    Seu navegador não permite gravar aqui — envie um arquivo de áudio abaixo.
                </p>

                <!-- Ou upload de arquivo -->
                <div class="border-t border-frame pt-4 space-y-2">
                    <label class="block text-sm text-muted">Ou envie um arquivo (MP3, M4A, AAC, OGG, WAV — até {{ Math.round(maxBytes / 1024 / 1024) }} MB)</label>
                    <input
                        ref="fileInput"
                        type="file"
                        accept="audio/*"
                        class="block w-full text-sm text-cream file:mr-3 file:rounded-lg file:border-0 file:bg-gold/15 file:px-4 file:py-2 file:text-gold"
                        @change="onFileChange"
                    />
                </div>

                <div class="flex items-center gap-4">
                    <Button variant="primary" size="sm" :loading="busy" :disabled="!hasSomethingToSend || busy" @click="send">
                        Enviar para análise
                    </Button>
                    <p v-if="error" class="text-sm text-danger">{{ error }}</p>
                    <p v-else-if="success" class="text-sm text-gold">{{ success }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
