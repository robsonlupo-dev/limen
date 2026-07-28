<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import { TAG_GROUPS, MAX_TAGS } from '@/lib/performerAttributes'

// Os interesses do membro usam o MESMO conjunto de slugs das tags da performer
// — é o que torna o cruzamento de afinidade possível. Por isso a tela importa
// de performerAttributes em vez de ter uma tabela própria de rótulos: duas
// listas divergiriam no primeiro slug novo, e o chip renderizaria o slug cru.
//
// MAX_TAGS espelha User::MAX_INTERESTS (os dois valem 8, pelo mesmo motivo de a
// interseção ser a medida). O servidor revalida — isto aqui impede o estado
// inválido, não substitui a validação.
const MAX_INTERESTS = MAX_TAGS

const props = defineProps({
    profile: { type: Object, required: true },
})

const form = useForm({
    interests: [...(props.profile.interests ?? [])],
    seeking: props.profile.seeking ?? '',
})

// Teto de MAX_INTERESTS. Desmarcar sempre funciona; marcar só até o teto —
// senão quem está no limite fica sem entender por que o clique não pega.
const interestCount = computed(() => form.interests.length)
const limitReached = computed(() => interestCount.value >= MAX_INTERESTS)

function isSelected(value) {
    return form.interests.includes(value)
}

function toggleInterest(value) {
    const i = form.interests.indexOf(value)
    if (i !== -1) {
        form.interests.splice(i, 1)
        return
    }
    if (limitReached.value) return
    form.interests.push(value)
}

function save() {
    form.put(route('consumer.profile.update'), { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Meu perfil">
        <div class="max-w-2xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Meu perfil</h1>
                    <p class="text-muted text-sm">Seus interesses e o que você procura no Limen.</p>
                </div>
                <Link :href="route('consumer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <!-- A copy de privacidade fica ANTES do formulário, não num rodapé:
                 o membro precisa saber quem vê o dado antes de escrever, não
                 depois de salvar. É a mesma disciplina da tela de edição da
                 performer, invertida — lá a copy avisa que o campo é público. -->
            <div class="rounded-xl border border-gold/30 bg-gold/5 p-5 space-y-1">
                <p class="text-cream font-medium text-sm">Isto é só seu</p>
                <p class="text-muted text-sm">
                    Nenhuma performer vê seus interesses nem o que você escreve aqui. Usamos
                    esses dados para te mostrar perfis mais próximos do que você procura — e
                    para nada além disso.
                </p>
            </div>

            <form class="rounded-xl border border-frame bg-surface p-6 space-y-6" @submit.prevent="save">
                <!-- Interesses -->
                <div class="flex flex-col gap-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-sm font-medium text-cream">Interesses</span>
                        <span class="text-xs text-muted tabular-nums shrink-0">
                            {{ interestCount }}/{{ MAX_INTERESTS }} selecionadas
                        </span>
                    </div>
                    <p class="text-xs text-muted">
                        Escolha até {{ MAX_INTERESTS }} que combinam com você.
                    </p>

                    <div v-for="group in TAG_GROUPS" :key="group.key" class="space-y-2">
                        <p class="text-xs text-muted uppercase tracking-wide">{{ group.label }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="tag in group.tags"
                                :key="tag.value"
                                type="button"
                                :disabled="!isSelected(tag.value) && limitReached"
                                :aria-pressed="isSelected(tag.value)"
                                class="rounded-full border px-3 py-1.5 text-xs transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                :class="isSelected(tag.value)
                                    ? 'border-gold bg-gold/10 text-gold'
                                    : 'border-frame bg-surface-2 text-cream hover:border-gold/50'"
                                @click="toggleInterest(tag.value)"
                            >
                                {{ tag.label }}
                            </button>
                        </div>
                    </div>

                    <p v-if="limitReached" class="text-xs text-muted">
                        Limite atingido. Desmarque um interesse para escolher outro.
                    </p>
                    <p v-if="form.errors.interests" class="text-xs text-danger">{{ form.errors.interests }}</p>
                </div>

                <!-- O que estou buscando -->
                <div class="flex flex-col gap-1.5 border-t border-frame pt-6">
                    <label for="seeking" class="text-sm font-medium text-cream">O que estou buscando</label>
                    <textarea
                        id="seeking"
                        v-model="form.seeking"
                        rows="4"
                        maxlength="1000"
                        placeholder="Descreva o que você procura no Limen..."
                        class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                    />
                    <div class="flex items-baseline justify-end">
                        <span class="text-xs text-muted tabular-nums shrink-0">{{ form.seeking.length }}/1000</span>
                    </div>
                    <p v-if="form.errors.seeking" class="text-xs text-danger">{{ form.errors.seeking }}</p>
                </div>

                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Salvando...' : 'Salvar' }}
                </Button>
            </form>
        </div>
    </AppLayout>
</template>
