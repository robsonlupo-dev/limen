<script setup>
// Enquadramento interativo do upload de avatar/capa (cropperjs).
//
// A performer arrasta/redimensiona a foto dentro do formato ANTES de enviar, e
// vê exatamente o recorte que vai para o card do catálogo. É UX: o corte
// DEFINITIVO é server-side (ImageProcessingService, crop:true) — o backend nunca
// confia no recorte do cliente. Aqui a saída já é um JPEG no formato pedido, o
// que também encolhe o upload (o original de celular não sobe inteiro).
//
// Contrato: o pai controla o ciclo pela prop `file`. Setar um File abre o modal;
// no confirmar, emite `crop` com o File já recortado; no cancelar, emite
// `cancel`. Em ambos os casos o pai deve zerar `file` — a limpeza (destruir o
// cropper, revogar o object URL) mora no watcher da transição para null e no
// onBeforeUnmount, então há uma dona só do teardown.
import { onBeforeUnmount, ref, watch } from 'vue'
import Cropper from 'cropperjs'
import 'cropperjs/dist/cropper.css'

const props = defineProps({
    // File escolhido pelo <input>; null = modal fechado.
    file: { type: File, default: null },
    // 1 = avatar (quadrado), 3 = capa (3:1). Trava a proporção do recorte.
    aspectRatio: { type: Number, required: true },
    // Largura do JPEG de saída; a altura sai de width / aspectRatio.
    outputWidth: { type: Number, default: 512 },
    title: { type: String, default: 'Enquadre sua foto' },
    hint: { type: String, default: '' },
})

const emit = defineEmits(['crop', 'cancel'])

const imgEl = ref(null)
const objectUrl = ref(null)
const processing = ref(false)
let cropper = null

function teardown() {
    if (cropper) {
        cropper.destroy()
        cropper = null
    }
    if (objectUrl.value) {
        URL.revokeObjectURL(objectUrl.value)
        objectUrl.value = null
    }
    processing.value = false
}

// Inicializa o cropper só depois que a <img> carregou — cropperjs mede as
// dimensões renderizadas no momento do init, então fazê-lo antes do load daria
// um viewport com tamanho zero.
function onImageLoad() {
    if (cropper) {
        cropper.destroy()
        cropper = null
    }
    cropper = new Cropper(imgEl.value, {
        aspectRatio: props.aspectRatio,
        viewMode: 1, // o recorte não sai da imagem
        autoCropArea: 1, // começa cobrindo o máximo possível
        dragMode: 'move',
        background: false,
        responsive: true,
        restore: false,
        checkOrientation: false, // o EXIF já morre no re-encode server-side
    })
}

watch(
    () => props.file,
    (file) => {
        teardown()
        if (file) {
            objectUrl.value = URL.createObjectURL(file)
        }
    },
)

function confirm() {
    if (!cropper || processing.value) return
    processing.value = true

    const canvas = cropper.getCroppedCanvas({
        width: props.outputWidth,
        height: Math.round(props.outputWidth / props.aspectRatio),
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
    })

    // Se o cropper ainda não mediu a imagem, getCroppedCanvas devolve null e o
    // toBlob abaixo lançaria, prendendo o botão em "Processando…". Solta o estado.
    if (!canvas) {
        processing.value = false
        return
    }

    // toBlob é assíncrono; o cropper segue vivo até o pai zerar `file` após o
    // emit, então getCroppedCanvas acima já capturou tudo o que precisamos.
    canvas.toBlob(
        (blob) => {
            if (!blob) {
                processing.value = false
                return
            }
            const name = props.aspectRatio === 1 ? 'avatar.jpg' : 'cover.jpg'
            emit('crop', new File([blob], name, { type: 'image/jpeg' }))
        },
        'image/jpeg',
        0.9,
    )
}

function cancel() {
    emit('cancel')
}

onBeforeUnmount(teardown)
</script>

<template>
    <Teleport to="body">
        <div
            v-if="file"
            class="fixed inset-0 z-[9500] flex items-center justify-center bg-black/80 p-4"
            role="dialog"
            aria-modal="true"
            :aria-label="title"
        >
            <div class="w-full max-w-lg rounded-xl border border-[#2A2A30] bg-[#16161A] shadow-2xl">
                <div class="border-b border-[#2A2A30] px-5 py-4">
                    <h2 class="font-serif text-lg text-[#F5F5F2]">{{ title }}</h2>
                    <p v-if="hint" class="mt-1 text-xs text-[#9A9A93]">{{ hint }}</p>
                </div>

                <!-- Altura limitada: o cropper mede a <img> renderizada, então
                     o max-height mantém o viewport dentro da tela. -->
                <div class="max-h-[60vh] overflow-hidden bg-black/40 p-3">
                    <img
                        ref="imgEl"
                        :src="objectUrl"
                        alt="Recorte da imagem"
                        class="block max-h-[54vh] max-w-full"
                        @load="onImageLoad"
                    />
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-[#2A2A30] px-5 py-4">
                    <button
                        type="button"
                        class="text-sm text-[#9A9A93] transition-colors hover:text-[#F5F5F2]"
                        @click="cancel"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        :disabled="processing"
                        class="rounded-lg bg-gold px-5 py-2 text-sm font-medium text-[#0A0A0B] transition-opacity hover:opacity-90 disabled:opacity-40"
                        @click="confirm"
                    >
                        {{ processing ? 'Processando…' : 'Usar esta foto' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
