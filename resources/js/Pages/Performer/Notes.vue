<script setup>
import { computed, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import { deleteJson, putJson } from '@/lib/http'

const props = defineProps({
    notes: { type: Object, required: true },
})

// Cópia local editável dos itens da página — o membro nunca aparece por id, só
// pelo FanAlias que veio do servidor.
const items = ref(props.notes.data.map((n) => ({ ...n })))

const search = ref('')

// Busca client-side: o conteúdo é cifrado no banco, então não há filtro no SQL.
// Filtra a página carregada por rótulo e texto — bounded, é o conjunto que ESTA
// performer anotou.
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (q === '') return items.value
    return items.value.filter(
        (n) =>
            n.label.toLowerCase().includes(q) ||
            (n.content ?? '').toLowerCase().includes(q),
    )
})

const editor = reactive({
    open: false,
    handle: null,
    label: '',
    draft: '',
    saving: false,
    error: '',
})

const toastMessage = ref('')

function toast(message) {
    toastMessage.value = message
    setTimeout(() => (toastMessage.value = ''), 4000)
}

function openEditor(note) {
    editor.open = true
    editor.handle = note.member_handle
    editor.label = note.label
    editor.draft = note.content ?? ''
    editor.error = ''
    editor.saving = false
}

function closeEditor() {
    editor.open = false
    editor.handle = null
}

async function saveNote() {
    if (editor.saving) return
    const text = editor.draft.trim()

    if (text === '') {
        await removeNote(editor.handle)
        return
    }

    editor.error = ''
    editor.saving = true
    try {
        const data = await putJson(route('performer.notes.save', editor.handle), { content: text })
        const idx = items.value.findIndex((n) => n.member_handle === editor.handle)
        if (idx !== -1) {
            items.value[idx] = { ...items.value[idx], ...data.note }
        }
        toast('Nota salva')
        closeEditor()
    } catch (error) {
        editor.error =
            error.status === 429
                ? 'Muitas edições em pouco tempo. Aguarde um instante.'
                : (error.data?.message ?? 'Não foi possível salvar a nota. Tente novamente.')
    } finally {
        editor.saving = false
    }
}

async function removeNote(handle) {
    editor.error = ''
    editor.saving = true
    try {
        await deleteJson(route('performer.notes.destroy', handle))
        items.value = items.value.filter((n) => n.member_handle !== handle)
        toast('Nota removida')
        closeEditor()
    } catch (error) {
        editor.error = error.data?.message ?? 'Não foi possível remover a nota. Tente novamente.'
    } finally {
        editor.saving = false
    }
}
</script>

<template>
    <AppLayout title="Minhas notas">
        <div class="max-w-4xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Minhas notas</h1>
                    <p class="text-muted text-sm">
                        Anotações privadas sobre seus membros. Só você vê — eles nunca sabem que existem.
                    </p>
                </div>
                <Link :href="route('performer.followers')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar aos seguidores
                </Link>
            </div>

            <div v-if="items.length > 0" class="relative">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Buscar por apelido ou conteúdo…"
                    class="w-full rounded-xl border border-frame bg-surface p-3 text-sm text-cream placeholder:text-muted/60 focus:border-gold/50 focus:outline-none"
                />
            </div>

            <div class="space-y-3">
                <div
                    v-if="items.length === 0"
                    class="rounded-xl border border-frame bg-surface p-10 text-center space-y-2"
                >
                    <p class="text-cream font-serif text-lg">Nenhuma nota ainda</p>
                    <p class="text-muted text-sm">
                        Abra a lista de seguidores e toque no ícone de nota ao lado de um membro para começar.
                    </p>
                </div>

                <div
                    v-else-if="filtered.length === 0"
                    class="rounded-xl border border-frame bg-surface p-10 text-center"
                >
                    <p class="text-muted text-sm">Nada encontrado para "{{ search }}".</p>
                </div>

                <div
                    v-for="note in filtered"
                    :key="note.member_handle"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-3"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-0.5">
                            <p class="text-cream">{{ note.label }}</p>
                            <p class="text-xs text-muted">Editada em {{ note.updated_at }}</p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <button
                                type="button"
                                class="text-sm text-gold hover:text-gold-light transition-colors"
                                @click="openEditor(note)"
                            >
                                Editar
                            </button>
                            <button
                                type="button"
                                class="text-sm text-danger hover:opacity-80 transition-opacity"
                                @click="removeNote(note.member_handle)"
                            >
                                Remover
                            </button>
                        </div>
                    </div>
                    <p class="text-sm text-cream/90 whitespace-pre-wrap break-words">{{ note.content }}</p>
                </div>
            </div>

            <div v-if="notes.last_page > 1" class="flex justify-center gap-2 pt-2">
                <Link
                    v-for="link in notes.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    class="rounded-lg border px-3 py-1.5 text-sm transition-colors no-underline"
                    :class="[
                        link.active ? 'border-gold bg-gold/10 text-gold' : 'border-frame text-muted hover:border-gold/40',
                        !link.url && 'pointer-events-none opacity-40',
                    ]"
                    v-html="link.label"
                />
            </div>
        </div>

        <!-- Editor da nota -->
        <div
            v-if="editor.open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="closeEditor"
        >
            <div class="w-full max-w-lg rounded-2xl border border-frame bg-surface p-6 space-y-4 shadow-2xl">
                <div class="space-y-1">
                    <h2 class="font-serif text-2xl text-cream">Nota sobre {{ editor.label }}</h2>
                    <p class="text-muted text-xs">Anotação privada, só sua.</p>
                </div>

                <textarea
                    v-model="editor.draft"
                    rows="6"
                    maxlength="5000"
                    class="w-full rounded-xl border border-frame bg-surface-2 p-3 text-sm text-cream placeholder:text-muted/60 focus:border-gold/50 focus:outline-none"
                ></textarea>

                <p v-if="editor.error" class="text-xs text-danger">{{ editor.error }}</p>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button
                        type="button"
                        class="text-sm text-muted hover:text-cream transition-colors"
                        :disabled="editor.saving"
                        @click="closeEditor"
                    >
                        Cancelar
                    </button>
                    <Button variant="primary" size="sm" :loading="editor.saving" @click="saveNote">
                        Salvar
                    </Button>
                </div>
            </div>
        </div>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="toastMessage"
                class="fixed bottom-6 left-1/2 -translate-x-1/2 rounded-lg border border-gold/30 bg-surface px-5 py-3 text-sm text-cream shadow-2xl z-50"
            >
                {{ toastMessage }}
            </div>
        </Transition>
    </AppLayout>
</template>
