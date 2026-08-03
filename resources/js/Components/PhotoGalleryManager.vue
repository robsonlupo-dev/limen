<script setup>
import { computed, ref } from 'vue'
import { postForm, deleteJson, patchJson } from '@/lib/http'

// Galeria de fotos do perfil (Sprint 10). Consome os endpoints JSON de gestão
// (`performer.gallery.*`), que sempre devolvem a lista atualizada — a fonte da
// verdade é o servidor, e esta tela só reflete o que ele responde. O cap de 6 é
// enforçado no servidor sob lock; aqui ele só desenha o estado e evita o clique
// que já sabemos que seria recusado.
const props = defineProps({
    initialPhotos: { type: Array, default: () => [] },
    maxPhotos: { type: Number, required: true },
})

const photos = ref([...props.initialPhotos])
const uploading = ref(false)
const error = ref(null)

const count = computed(() => photos.value.length)
const isFull = computed(() => count.value >= props.maxPhotos)

async function upload(event) {
    const file = event.target.files[0]
    // O <input> é limpo sempre no fim: sem isso, escolher o MESMO arquivo duas
    // vezes seguidas não dispara o change na segunda.
    event.target.value = ''
    if (!file) return

    error.value = null
    uploading.value = true

    try {
        const data = new FormData()
        data.append('foto', file)
        const res = await postForm(route('performer.gallery.store'), data)
        photos.value = res.photos
    } catch (e) {
        // O 422 traz `message` (imagem inválida ou cap atingido). Fallback
        // genérico para erro de rede.
        error.value = e?.data?.message ?? 'Não foi possível enviar a foto.'
    } finally {
        uploading.value = false
    }
}

async function remove(id) {
    error.value = null
    try {
        const res = await deleteJson(route('performer.gallery.destroy', id))
        photos.value = res.photos
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível apagar a foto.'
    }
}

// Público ↔ privado por foto (Sprint 13). Manda o estado DESEJADO (não inverte
// às cegas), então duplo clique é inofensivo. O servidor devolve a lista com o
// `is_private` atualizado — ele é a fonte da verdade.
const togglingId = ref(null)

async function toggleVisibility(photo) {
    if (togglingId.value !== null) return
    togglingId.value = photo.id
    error.value = null
    try {
        const res = await patchJson(route('performer.gallery.visibility', photo.id), {
            is_private: !photo.is_private,
        })
        photos.value = res.photos
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível alterar a visibilidade.'
    } finally {
        togglingId.value = null
    }
}

// ── Reordenação por arrastar ────────────────────────────────────────────────
// DnD nativo do HTML5, sem dependência. A reordenação é otimista: move local
// primeiro (a tela responde na hora) e persiste depois; se o servidor recusar,
// restaura a ordem que ele devolver — ele é a fonte da verdade.
const dragIndex = ref(null)

function onDragStart(index) {
    dragIndex.value = index
}

function onDrop(targetIndex) {
    const from = dragIndex.value
    dragIndex.value = null
    if (from === null || from === targetIndex) return

    const next = [...photos.value]
    const [moved] = next.splice(from, 1)
    next.splice(targetIndex, 0, moved)
    photos.value = next

    persistOrder()
}

async function persistOrder() {
    error.value = null
    try {
        const res = await patchJson(route('performer.gallery.reorder'), {
            ids: photos.value.map((p) => p.id),
        })
        photos.value = res.photos
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível salvar a nova ordem.'
    }
}
</script>

<template>
    <div class="rounded-xl border border-frame bg-surface p-6 space-y-4">
        <div class="flex items-baseline justify-between gap-3">
            <h2 class="font-serif text-xl text-cream">Galeria de fotos</h2>
            <span class="text-xs text-muted tabular-nums shrink-0">{{ count }}/{{ maxPhotos }} fotos</span>
        </div>
        <p class="text-xs text-muted">
            Fotos do seu perfil público, além do avatar e da capa. Arraste para reordenar — a
            primeira aparece primeiro no carrossel.
        </p>

        <div v-if="photos.length" class="grid grid-cols-3 gap-3 sm:grid-cols-4">
            <div
                v-for="(photo, index) in photos"
                :key="photo.id"
                draggable="true"
                class="group relative aspect-square overflow-hidden rounded-lg border border-frame bg-surface-2 cursor-move"
                :class="{ 'opacity-50': dragIndex === index }"
                @dragstart="onDragStart(index)"
                @dragover.prevent
                @drop="onDrop(index)"
            >
                <img :src="photo.url" alt="Foto da galeria" class="h-full w-full object-cover" />
                <button
                    type="button"
                    aria-label="Apagar foto"
                    class="absolute top-1 right-1 flex h-7 w-7 items-center justify-center rounded-full bg-black/60 text-cream opacity-0 transition-opacity group-hover:opacity-100 hover:bg-danger focus:opacity-100"
                    @click="remove(photo.id)"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </button>
                <!-- Toggle público/privado (Sprint 13). Marca a foto como privada
                     (só quem tiver acesso aprovado vê no perfil público). O rótulo
                     mostra o estado ATUAL. -->
                <button
                    type="button"
                    :disabled="togglingId === photo.id"
                    :aria-pressed="photo.is_private"
                    :title="photo.is_private ? 'Privada — toque para tornar pública' : 'Pública — toque para tornar privada'"
                    class="absolute bottom-1 left-1 right-1 flex items-center justify-center gap-1 rounded-md px-1.5 py-1 text-[11px] font-medium transition-colors disabled:opacity-50"
                    :class="photo.is_private ? 'bg-gold/90 text-background' : 'bg-black/60 text-cream opacity-0 group-hover:opacity-100 focus:opacity-100'"
                    @click="toggleVisibility(photo)"
                >
                    <span aria-hidden="true">{{ photo.is_private ? '🔒' : '🌐' }}</span>
                    {{ photo.is_private ? 'Privada' : 'Pública' }}
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <label v-if="!isFull" class="cursor-pointer inline-block self-start">
                <span class="inline-flex items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                    {{ uploading ? 'Enviando...' : 'Adicionar foto' }}
                </span>
                <input
                    type="file"
                    accept="image/jpeg,image/png"
                    class="hidden"
                    :disabled="uploading"
                    @change="upload"
                />
            </label>
            <p v-else class="text-xs text-muted">
                Limite de {{ maxPhotos }} fotos atingido. Apague uma para adicionar outra.
            </p>
            <p class="text-xs text-muted">JPG ou PNG. Até 5 MB.</p>
            <p v-if="error" class="text-xs text-danger">{{ error }}</p>
        </div>
    </div>
</template>
