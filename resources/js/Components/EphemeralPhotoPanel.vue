<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
import { deleteJson, postForm } from '@/lib/http'

/**
 * "Minhas fotos" — painel do MEMBRO sobre as próprias fotos efêmeras.
 *
 * A tela do titular é a única que pode mostrar a foto de volta, e ainda assim
 * em FAIXA e não em relógio (§ 1.2): o valor da faixa é o mesmo que a performer
 * recebe, e manter as duas no mesmo vocabulário evita que alguém "melhore" esta
 * aqui para um countdown e depois reuse o componente do outro lado.
 */
const props = defineProps({
    photos: { type: Array, required: true }, // [{ id, expires_slot, shared_with }]
    limit: { type: Number, required: true },
    ttlOptions: { type: Array, required: true }, // [{ hours, label }]
})

const file = ref(null)
const fileInput = ref(null)
const ttl = ref(props.ttlOptions[0]?.hours ?? 24)
const uploading = ref(false)
const error = ref('')
const revoking = ref(null)

const atLimit = computed(() => props.photos.length >= props.limit)

function pick(event) {
    file.value = event.target.files?.[0] ?? null
    error.value = ''
}

async function upload() {
    if (!file.value || uploading.value) return

    uploading.value = true
    error.value = ''

    const form = new FormData()
    form.append('foto', file.value)
    form.append('ttl_horas', ttl.value)

    try {
        await postForm(route('member.photos.store'), form)
        file.value = null
        if (fileInput.value) fileInput.value.value = ''
        router.reload({ only: ['photos'] })
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível enviar a foto.'
    } finally {
        uploading.value = false
    }
}

async function revoke(photo) {
    if (revoking.value) return
    revoking.value = photo.id
    error.value = ''
    try {
        await deleteJson(route('member.photos.destroy', photo.id))
        router.reload({ only: ['photos'] })
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível revogar a foto.'
    } finally {
        revoking.value = null
    }
}
</script>

<template>
    <section class="rounded-2xl border border-frame bg-surface p-6">
        <div class="flex items-baseline justify-between">
            <h2 class="font-serif text-lg text-cream">Minhas fotos</h2>
            <span class="text-xs text-muted">{{ photos.length }} de {{ limit }} ativas</span>
        </div>

        <p class="mt-2 text-xs text-muted">
            Fotos privadas que você pode compartilhar dentro de um chat ativo. Elas somem do
            nosso servidor quando o prazo acaba — mas quem já viu, viu.
        </p>

        <!-- Copy de retenção UNIFORME: aparece para toda foto, sempre, e não só
             quando há denúncia. É o que permite ao revoke responder igual nos
             dois casos — uma frase condicional ("esta foto está em análise")
             identificaria a denunciante para o denunciado, e a foto costuma ter
             uma destinatária só. Ver MemberPhotoService::destroyForMember(). -->
        <p class="mt-2 text-xs text-muted">
            Ao revogar, a foto sai do ar na hora para quem recebeu. Por exigência de
            moderação, a cópia técnica pode ficar retida por um período antes do descarte
            definitivo.
        </p>

        <!-- Lista -->
        <ul v-if="photos.length" class="mt-5 space-y-3">
            <li
                v-for="photo in photos"
                :key="photo.id"
                class="flex items-center gap-4 rounded-xl border border-frame/60 bg-background/40 p-3"
            >
                <img
                    :src="route('member.photos.image', photo.id)"
                    alt="Foto efêmera"
                    class="h-16 w-16 shrink-0 rounded-lg object-cover"
                />
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-cream">{{ photo.expires_slot }}</p>
                    <!-- O agregado do § 1.1: não previne nada, põe o número na
                         frente de quem carrega o risco. -->
                    <p class="text-xs text-muted">
                        {{ photo.shared_with === 0
                            ? 'Ainda não compartilhada'
                            : `Compartilhada com ${photo.shared_with} ${photo.shared_with === 1 ? 'performer' : 'performers'}` }}
                    </p>
                </div>
                <button
                    class="shrink-0 text-xs text-danger hover:underline disabled:opacity-50"
                    :disabled="revoking === photo.id"
                    @click="revoke(photo)"
                >
                    {{ revoking === photo.id ? 'Revogando…' : 'Revogar' }}
                </button>
            </li>
        </ul>
        <p v-else class="mt-5 text-sm text-muted">Você não tem fotos ativas.</p>

        <!-- Upload -->
        <div class="mt-6 border-t border-frame/60 pt-5">
            <p v-if="atLimit" class="text-xs text-muted">
                Você atingiu o limite de {{ limit }} fotos ativas. Revogue uma ou espere expirar
                para enviar outra.
            </p>
            <div v-else class="space-y-3">
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png"
                    class="block w-full text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-gold/10 file:px-4 file:py-2 file:text-xs file:text-gold hover:file:bg-gold/20"
                    @change="pick"
                />
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-muted">
                        Expira em
                        <select
                            v-model.number="ttl"
                            class="ml-2 rounded-lg border border-frame bg-background px-3 py-2 text-xs text-cream focus:border-gold focus:outline-none"
                        >
                            <option v-for="option in ttlOptions" :key="option.hours" :value="option.hours">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <Button variant="ghost" size="sm" :loading="uploading" :disabled="!file" @click="upload">
                        Enviar foto
                    </Button>
                </div>
                <p class="text-xs text-muted">JPEG ou PNG, até 5 MB. Removemos a localização do arquivo no envio.</p>
            </div>
            <p v-if="error" class="mt-3 text-xs text-danger">{{ error }}</p>
        </div>
    </section>
</template>
