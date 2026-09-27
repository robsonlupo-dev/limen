<script setup>
import { ref } from 'vue'
import { postJson, patchJson, deleteJson, errorMessage } from '@/lib/http'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'

// Gerência de Destaques da performer (roadmap social, Onda 1a). Só stories
// PÚBLICOS ativos podem entrar. Cada mutação devolve a lista atualizada.
const props = defineProps({
    initialHighlights: { type: Array, default: () => [] }, // [{id,title,items:[{id,url}]}]
    stories: { type: Array, default: () => [] },           // [{id,url}] stories públicos ativos
})

const highlights = ref([...props.initialHighlights])
const newTitle = ref('')
const busy = ref(false)
const error = ref('')
const pickerFor = ref(null) // id da coleção com o seletor de story aberto

function apply(res) {
    highlights.value = res.highlights ?? []
    error.value = ''
}

async function run(fn) {
    if (busy.value) return
    busy.value = true
    error.value = ''
    try {
        apply(await fn())
    } catch (e) {
        error.value = errorMessage(e)
    } finally {
        busy.value = false
    }
}

async function createCollection() {
    if (!newTitle.value.trim()) return
    await run(async () => {
        const res = await postJson(route('performer.highlights.store'), { title: newTitle.value })
        newTitle.value = ''
        return res
    })
}

const renameDraft = ref({}) // { [collectionId]: title }
async function rename(h) {
    const title = (renameDraft.value[h.id] ?? '').trim()
    if (!title || title === h.title) { renameDraft.value[h.id] = undefined; return }
    await run(() => patchJson(route('performer.highlights.rename', h.id), { title }))
    renameDraft.value[h.id] = undefined
}

async function addStory(collectionId, storyId) {
    await run(async () => {
        const res = await postJson(route('performer.highlights.add-story', [collectionId, storyId]), {})
        pickerFor.value = null
        return res
    })
}

async function removeItem(itemId) {
    await run(() => deleteJson(route('performer.highlights.remove-item', itemId)))
}

async function destroyCollection(h) {
    await run(() => deleteJson(route('performer.highlights.destroy', h.id)))
}
</script>

<template>
    <div class="rounded-xl border border-frame bg-surface p-6 space-y-5">
        <div class="space-y-1">
            <h2 class="font-serif text-xl text-cream">Destaques</h2>
            <p class="text-muted text-sm">
                Coleções fixas no seu perfil, feitas a partir dos seus stories públicos.
                Diferente do story, elas não somem em 24h.
            </p>
        </div>

        <p v-if="error" class="text-sm text-danger">{{ error }}</p>

        <!-- Nova coleção -->
        <div class="flex items-end gap-2">
            <div class="flex-1">
                <Input id="new_highlight" v-model="newTitle" label="Nova coleção" type="text" maxlength="30" placeholder="Ex.: Ensaios" />
            </div>
            <Button variant="primary" :loading="busy" :disabled="!newTitle.trim()" @click="createCollection">Criar</Button>
        </div>

        <!-- Coleções -->
        <div v-for="h in highlights" :key="h.id" class="rounded-lg border border-frame p-4 space-y-3">
            <div class="flex items-center justify-between gap-2">
                <input
                    :value="renameDraft[h.id] ?? h.title"
                    class="min-w-0 flex-1 rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream"
                    maxlength="30"
                    @input="renameDraft[h.id] = $event.target.value"
                    @blur="rename(h)"
                    @keydown.enter.prevent="rename(h)"
                />
                <button type="button" class="min-h-[44px] text-sm text-muted hover:text-danger transition-colors" @click="destroyCollection(h)">
                    Apagar
                </button>
            </div>

            <!-- Itens -->
            <div class="flex flex-wrap gap-2">
                <div v-for="it in h.items" :key="it.id" class="relative">
                    <img :src="it.url" alt="" class="h-16 w-16 rounded-md object-cover border border-frame" loading="lazy" />
                    <button
                        type="button"
                        class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-black/80 text-xs text-white"
                        aria-label="Remover item"
                        @click="removeItem(it.id)"
                    >✕</button>
                </div>
                <p v-if="!h.items.length" class="text-xs text-muted self-center">Nenhum item ainda.</p>
            </div>

            <!-- Adicionar story -->
            <div>
                <button
                    v-if="pickerFor !== h.id"
                    type="button"
                    class="min-h-[44px] text-sm text-gold hover:text-gold-light transition-colors"
                    @click="pickerFor = h.id"
                >
                    + Adicionar story
                </button>
                <div v-else class="space-y-2">
                    <p v-if="!stories.length" class="text-xs text-muted">Você não tem stories públicos ativos para adicionar.</p>
                    <div v-else class="flex flex-wrap gap-2">
                        <button
                            v-for="s in stories"
                            :key="s.id"
                            type="button"
                            class="h-16 w-16 overflow-hidden rounded-md border border-frame hover:border-gold transition-colors"
                            @click="addStory(h.id, s.id)"
                        >
                            <img :src="s.url" alt="" class="h-full w-full object-cover" loading="lazy" />
                        </button>
                    </div>
                    <button type="button" class="text-xs text-muted hover:text-cream" @click="pickerFor = null">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</template>
