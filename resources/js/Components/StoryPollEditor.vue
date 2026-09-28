<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { postJson, deleteJson, errorMessage } from '@/lib/http'

/**
 * Editor de ENQUETE de um story, no painel da performer (roadmap social, Onda 1b).
 *
 * Sem enquete: um botão revela o formulário (pergunta + 2–4 opções). Com enquete:
 * mostra a distribuição — AGREGADO (contagem por opção), nunca "quem votou" — e um
 * botão para remover. O servidor é o guard de verdade (ownership, não-exclusivo,
 * nº de opções, filtro anti-contato); aqui é só conveniência.
 */
const props = defineProps({
    storyId: { type: Number, required: true },
    // { type, prompt, options, results:[counts], total } | null
    poll: { type: Object, default: null },
    // Story exclusivo não tem enquete (o servidor recusa); a UI nem oferece.
    canPoll: { type: Boolean, default: true },
})

const MIN = 2
const MAX = 4

const editing = ref(false)
const prompt = ref('')
const options = ref(['', ''])
const busy = ref(false)
const error = ref('')

const total = computed(() => props.poll?.total ?? 0)

function pct(i) {
    if (! props.poll || ! total.value) return 0
    return Math.round(((props.poll.results?.[i] ?? 0) / total.value) * 100)
}

function addOption() {
    if (options.value.length < MAX) options.value.push('')
}

function removeOption(i) {
    if (options.value.length > MIN) options.value.splice(i, 1)
}

function reset() {
    editing.value = false
    prompt.value = ''
    options.value = ['', '']
    error.value = ''
}

async function create() {
    if (busy.value) return
    const opts = options.value.map((o) => o.trim()).filter((o) => o !== '')
    if (! prompt.value.trim() || opts.length < MIN) {
        error.value = `Escreva a pergunta e ao menos ${MIN} opções.`
        return
    }

    busy.value = true
    error.value = ''
    try {
        await postJson(route('performer.stories.poll.store', props.storyId), {
            prompt: prompt.value.trim(),
            options: opts,
        })
        reset()
        router.reload({ only: ['stories'] })
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível criar a enquete.')
    } finally {
        busy.value = false
    }
}

async function remove() {
    if (busy.value) return
    busy.value = true
    error.value = ''
    try {
        await deleteJson(route('performer.stories.poll.destroy', props.storyId))
        router.reload({ only: ['stories'] })
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível remover a enquete.')
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="mt-2 border-t border-frame/50 pt-2">
        <!-- Com enquete: distribuição agregada + remover -->
        <div v-if="poll">
            <p class="text-xs font-medium text-cream">Enquete: {{ poll.prompt }}</p>
            <ul class="mt-1 space-y-1">
                <li v-for="(opt, i) in poll.options" :key="i" class="relative overflow-hidden rounded border border-frame/40 px-2 py-1 text-xs text-muted">
                    <span class="absolute inset-y-0 left-0 bg-gold/15" :style="{ width: pct(i) + '%' }" aria-hidden="true" />
                    <span class="relative flex items-center justify-between gap-2">
                        <span class="truncate">{{ opt }}</span>
                        <span class="shrink-0 tabular-nums text-cream">{{ pct(i) }}%</span>
                    </span>
                </li>
            </ul>
            <p class="mt-1 text-[11px] text-muted">
                {{ total }} {{ total === 1 ? 'voto' : 'votos' }} · você vê a distribuição, nunca quem votou.
            </p>
            <button class="mt-1 text-[11px] text-danger hover:underline disabled:opacity-50" :disabled="busy" @click="remove">
                Remover enquete
            </button>
        </div>

        <!-- Sem enquete: criar (só se o story permitir) -->
        <div v-else-if="canPoll">
            <button v-if="!editing" class="text-[11px] text-gold hover:underline" @click="editing = true">
                + Adicionar enquete
            </button>
            <div v-else class="space-y-1.5">
                <input
                    v-model="prompt"
                    type="text"
                    maxlength="80"
                    placeholder="Pergunta da enquete"
                    class="block w-full rounded border border-frame bg-background px-2 py-1 text-xs text-cream focus:border-gold focus:outline-none"
                />
                <div v-for="(_, i) in options" :key="i" class="flex items-center gap-1">
                    <input
                        v-model="options[i]"
                        type="text"
                        maxlength="30"
                        :placeholder="`Opção ${i + 1}`"
                        class="block w-full rounded border border-frame bg-background px-2 py-1 text-xs text-cream focus:border-gold focus:outline-none"
                    />
                    <button v-if="options.length > MIN" class="shrink-0 text-xs text-muted hover:text-danger" title="Remover opção" @click="removeOption(i)">✕</button>
                </div>
                <div class="flex items-center gap-3 pt-0.5">
                    <button v-if="options.length < MAX" class="text-[11px] text-gold hover:underline" @click="addOption">+ opção</button>
                    <span class="flex-1" />
                    <button class="text-[11px] text-muted hover:underline" @click="reset">Cancelar</button>
                    <button class="text-[11px] text-gold hover:underline disabled:opacity-50" :disabled="busy" @click="create">Criar</button>
                </div>
            </div>
        </div>

        <p v-if="error" class="mt-1 text-[11px] text-danger">{{ error }}</p>
    </div>
</template>
