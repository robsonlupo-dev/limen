<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'

const props = defineProps({
    documents: { type: Array, required: true },
    isRevision: { type: Boolean, default: false },
})

// Os checkboxes já vêm marcados no modo revisão (nada pendente): a tela então é
// consulta, não um formulário que finge haver algo a fazer.
const form = useForm({
    content_policy: props.isRevision,
    performance_contract: props.isRevision,
})

const canSubmit = computed(() => form.content_policy && form.performance_contract)

function submit() {
    form.post(route('performer.documents.accept'))
}
</script>

<template>
    <AppLayout title="Aceite de documentos">
        <div class="max-w-2xl mx-auto px-6 py-12">
            <h1 class="font-serif text-3xl text-cream mb-2">Documentos da plataforma</h1>
            <p class="text-muted text-sm mb-8">
                Leia e aceite os dois documentos abaixo para continuar. Eles definem o que pode
                ser publicado no Limen e os termos da sua atuação na plataforma.
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <div
                    v-for="doc in documents"
                    :key="doc.type"
                    class="bg-surface border border-frame rounded-2xl p-6"
                >
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input
                            v-model="form[doc.type]"
                            type="checkbox"
                            class="mt-1 h-4 w-4 shrink-0 accent-gold"
                        />
                        <span class="text-sm text-cream">
                            Li e aceito a
                            <a
                                :href="doc.url"
                                target="_blank"
                                rel="noopener"
                                class="text-gold underline"
                            >{{ doc.title }}</a>
                            <span class="text-muted"> (versão {{ doc.version }})</span>
                        </span>
                    </label>

                    <p v-if="form.errors[doc.type]" class="text-danger text-sm mt-3 ml-7">
                        {{ form.errors[doc.type] }}
                    </p>
                </div>

                <div class="pt-4">
                    <Button type="submit" :disabled="!canSubmit" :loading="form.processing">
                        {{ isRevision ? 'Confirmar' : 'Aceitar e continuar' }}
                    </Button>
                    <p v-if="!canSubmit" class="text-muted text-xs mt-3">
                        Os dois aceites são obrigatórios.
                    </p>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
