<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
import { deleteJson, postForm } from '@/lib/http'

/**
 * "Meus Stories" — painel da PERFORMER sobre o próprio conteúdo de 24h.
 *
 * O que esta tela pode mostrar, e o que ela nunca mostra:
 *  - o contador de audiência vem do servidor já em FAIXA, e no nível exclusivo
 *    vem `null` (§ 2.2, decisão nº 3). A tela renderiza AUSÊNCIA — um "—" —, e
 *    **nunca `0`**: zero é um valor no mesmo domínio da faixa e afirmaria algo
 *    falso sobre a audiência. Também não há `if` de nível aqui: quem decide é o
 *    `PerformerStoryService::viewCount()`.
 *  - **não existe, e não pode existir, lista de quem viu** (§ 2.1). Seria
 *    exposição membro→performer sem piso nenhum — o buraco que o painel de
 *    visitantes existiu para tapar, e pior, porque ver story não exige seguir.
 *
 * O tempo restante em horas é permitido AQUI e seria proibido na foto efêmera
 * (§ 1.2): o prazo do story é único e fixo (24h) e o conteúdo é o dela, então o
 * número só diz quando ela mesma publicou.
 */
const props = defineProps({
    // [{ id, visibility_level, view_count (faixa|null), expires_in_hours, image_url, is_invite }]
    stories: { type: Array, required: true },
    visibilityLevels: { type: Array, required: true },
    // Teto de convites ATIVOS (Sprint 12). O usado é derivado de `stories`, não
    // uma prop separada — assim o contador segue os cards depois de publicar/apagar.
    inviteLimit: { type: Number, default: 2 },
})

const LEVEL_LABELS = {
    public: 'Público — quem me segue',
    subscribers: 'Assinantes — qualquer Círculo',
    exclusive: 'Exclusivo — Black e Founders',
}

const file = ref(null)
const fileInput = ref(null)
const level = ref(props.visibilityLevels[0] ?? 'public')
const sendAsInvite = ref(false)
const publishing = ref(false)
const deleting = ref(null)
const error = ref('')

const levelOptions = computed(() =>
    props.visibilityLevels.map((value) => ({ value, label: LEVEL_LABELS[value] ?? value })),
)

// Convites ativos derivados dos próprios cards — nunca uma segunda fonte para
// divergir. Cada story traz `is_invite`; a vaga se libera quando o convite
// expira (o card some da lista, que já é só de stories vivos).
const invitesUsed = computed(() => props.stories.filter((s) => s.is_invite).length)
const inviteFull = computed(() => invitesUsed.value >= props.inviteLimit)

function labelFor(value) {
    return LEVEL_LABELS[value] ?? value
}

function pick(event) {
    file.value = event.target.files?.[0] ?? null
    error.value = ''
}

async function publish() {
    if (!file.value || publishing.value) return

    publishing.value = true
    error.value = ''

    const form = new FormData()
    form.append('imagem', file.value)
    form.append('visibility_level', level.value)
    // Só manda o campo quando marcado — ausência = story normal. O servidor é o
    // guard do teto (o checkbox desabilitado é só conveniência de UI).
    if (sendAsInvite.value) form.append('is_invite', '1')

    try {
        await postForm(route('performer.stories.store'), form)
        file.value = null
        sendAsInvite.value = false
        if (fileInput.value) fileInput.value.value = ''
        router.reload({ only: ['stories'] })
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível publicar o story.'
    } finally {
        publishing.value = false
    }
}

async function remove(story) {
    if (deleting.value) return

    deleting.value = story.id
    error.value = ''

    try {
        await deleteJson(route('performer.stories.destroy', story.id))
        router.reload({ only: ['stories'] })
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível apagar o story.'
    } finally {
        deleting.value = null
    }
}
</script>

<template>
    <section class="rounded-2xl border border-frame bg-surface p-6">
        <div class="flex items-baseline justify-between">
            <h2 class="font-serif text-lg text-cream">Meus Stories</h2>
            <span class="text-xs text-muted">{{ stories.length }} {{ stories.length === 1 ? 'ativo' : 'ativos' }}</span>
        </div>

        <p class="mt-2 text-xs text-muted">
            Some sozinho em 24 horas. Você vê quantas pessoas viram, em faixa — nunca quem viu.
        </p>

        <!-- Lista -->
        <ul v-if="stories.length" class="mt-5 space-y-3">
            <li
                v-for="story in stories"
                :key="story.id"
                class="flex items-center gap-4 rounded-xl border border-frame/60 bg-background/40 p-3"
            >
                <!-- Thumbnail pela rota autenticada por sessão. Nunca URL
                     assinada (§ 2.3) — nem aqui, onde o viewer é ela mesma. -->
                <img
                    :src="story.image_url"
                    alt="Story"
                    class="h-16 w-16 shrink-0 rounded-lg object-cover"
                />
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-cream">
                        {{ labelFor(story.visibility_level) }}
                        <!-- Convite (Sprint 12): tag na publicação DELA. Novos
                             seguidores sem chat veem este story com destaque. -->
                        <span
                            v-if="story.is_invite"
                            class="ml-1 rounded-full bg-gold/10 px-2 py-0.5 text-[10px] text-gold"
                            title="Enviado como convite: novos seguidores sem chat veem com destaque"
                        >💌 Convite</span>
                    </p>
                    <p class="text-xs text-muted">
                        Expira em {{ story.expires_in_hours }}h ·
                        <span v-if="story.view_count !== null">{{ story.view_count }} viram</span>
                        <!-- Ausência, não zero: no nível exclusivo a pergunta
                             simplesmente não é respondida. -->
                        <span v-else title="Stories exclusivos não exibem contador">—</span>
                    </p>
                </div>
                <button
                    class="shrink-0 text-xs text-danger hover:underline disabled:opacity-50"
                    :disabled="deleting === story.id"
                    @click="remove(story)"
                >
                    {{ deleting === story.id ? 'Apagando…' : 'Apagar' }}
                </button>
            </li>
        </ul>
        <p v-else class="mt-5 text-sm text-muted">Você não tem stories ativos.</p>

        <!-- Publicar -->
        <div class="mt-6 border-t border-frame/60 pt-5 space-y-3">
            <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/png"
                class="block w-full text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-gold/10 file:px-4 file:py-2 file:text-xs file:text-gold hover:file:bg-gold/20"
                @change="pick"
            />
            <div class="flex flex-wrap items-center gap-3">
                <label class="text-xs text-muted">
                    Quem vê
                    <select
                        v-model="level"
                        class="ml-2 rounded-lg border border-frame bg-background px-3 py-2 text-xs text-cream focus:border-gold focus:outline-none"
                    >
                        <option v-for="option in levelOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <Button variant="ghost" size="sm" :loading="publishing" :disabled="!file" @click="publish">
                    Publicar Story
                </Button>
            </div>

            <!-- Convite via Stories (Sprint 12). O checkbox desabilita quando as
                 vagas acabam; o contador é derivado dos cards. O servidor é o
                 guard de verdade do teto. -->
            <label
                class="flex items-start gap-2 text-xs"
                :class="inviteFull && !sendAsInvite ? 'opacity-50' : ''"
            >
                <input
                    type="checkbox"
                    v-model="sendAsInvite"
                    :disabled="inviteFull && !sendAsInvite"
                    class="mt-0.5 rounded border-frame bg-background text-gold focus:ring-gold"
                />
                <span class="text-muted">
                    <span class="text-cream">Enviar como convite para novos seguidores</span>
                    <span class="ml-1 text-gold">({{ invitesUsed }}/{{ inviteLimit }} convites hoje)</span>
                    <br />
                    <span
                        title="Seguidores que ainda não conversaram com você verão este Story com destaque"
                    >
                        Seguidores que ainda não conversaram com você verão este Story com destaque.
                    </span>
                    <span v-if="inviteFull && !sendAsInvite" class="text-danger">
                        Espere um convite ativo expirar para enviar outro.
                    </span>
                </span>
            </label>

            <p class="text-xs text-muted">
                JPEG ou PNG, até 5 MB. Vídeo ainda não. Removemos a localização do arquivo na
                publicação.
            </p>
            <p v-if="error" class="text-xs text-danger">{{ error }}</p>
        </div>
    </section>
</template>
