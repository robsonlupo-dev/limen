<script setup>
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import PerformerOnboardingWizard from '@/Components/Onboarding/PerformerOnboardingWizard.vue'

const props = defineProps({
    tipo: { type: String, default: 'membro' },
})

// Sprint 7: o cadastro de performer virou o wizard de 5 passos (passos 1–3
// acontecem aqui; 4–5 continuam em /performer/onboarding após o redirect do
// register.store). O formulário de membro segue como era.
const isPerformer = computed(() => props.tipo === 'performer')

const worlds = [
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
]

const form = useForm({
    tipo: props.tipo,
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    birthdate: '',
    cpf: '',
    accept_terms: false,
    lgpd_consent: false,
    preferred_world: '',
})

function submit() {
    form.post(route('register.store'), {
        // O CPF também sai do form após o submit: ele não é persistido no
        // servidor, então não faz sentido continuar vivo no state do cliente.
        onFinish: () => form.reset('password', 'password_confirmation', 'cpf'),
    })
}
</script>

<template>
    <GuestLayout :title="isPerformer ? 'Torne-se Performer' : 'Criar conta'">
        <PerformerOnboardingWizard v-if="isPerformer" phase="register" />

        <div v-else class="min-h-[80vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-md">
                <div class="flex justify-center mb-8">
                    <PortalLogo :size="48" />
                </div>

                <div class="bg-surface border border-frame rounded-2xl p-8">
                    <h1 class="font-serif text-2xl text-cream mb-1">Criar conta</h1>
                    <p class="text-muted text-sm mb-8">Junte-se ao portal verificado.</p>

                    <form @submit.prevent="submit" novalidate class="space-y-5">
                        <Input
                            id="name"
                            v-model="form.name"
                            label="Nome completo"
                            type="text"
                            placeholder="Seu nome"
                            autocomplete="name"
                            :required="true"
                            :error="form.errors.name"
                        />

                        <Input
                            id="email"
                            v-model="form.email"
                            label="E-mail"
                            type="email"
                            placeholder="voce@email.com"
                            autocomplete="email"
                            :required="true"
                            :error="form.errors.email"
                        />

                        <Input
                            id="password"
                            v-model="form.password"
                            label="Senha"
                            type="password"
                            placeholder="Mínimo 8 caracteres"
                            autocomplete="new-password"
                            :required="true"
                            :error="form.errors.password"
                        />

                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            label="Confirmar senha"
                            type="password"
                            placeholder="Repita a senha"
                            autocomplete="new-password"
                            :required="true"
                            :error="form.errors.password_confirmation"
                        />

                        <Input
                            id="birthdate"
                            v-model="form.birthdate"
                            label="Data de nascimento"
                            type="date"
                            :required="true"
                            :error="form.errors.birthdate"
                        />

                        <!-- Membro: CPF exigido pelo ECA Digital. Validado no
                             servidor e descartado — nunca é gravado. -->
                        <div>
                            <Input
                                id="cpf"
                                v-model="form.cpf"
                                label="CPF"
                                type="text"
                                placeholder="000.000.000-00"
                                autocomplete="off"
                                :required="true"
                                :error="form.errors.cpf"
                            />
                            <p v-if="!form.errors.cpf" class="mt-1 text-xs text-muted">
                                Usado só para confirmar sua maioridade. Não armazenamos seu CPF.
                            </p>
                        </div>

                        <!-- World preference (member) -->
                        <div>
                            <label class="text-sm font-medium text-cream">
                                Qual mundo você quer explorar?
                                <span class="text-gold ml-0.5">*</span>
                            </label>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <button
                                    v-for="world in worlds"
                                    :key="world.value"
                                    type="button"
                                    class="rounded-lg border px-3 py-2 text-sm transition-colors"
                                    :class="[
                                        form.preferred_world === world.value
                                            ? 'border-gold text-gold bg-gold/10'
                                            : 'border-frame text-muted hover:border-gold/50',
                                    ]"
                                    @click="form.preferred_world = world.value"
                                >
                                    {{ world.label }}
                                </button>
                            </div>
                            <p v-if="form.errors.preferred_world" class="text-xs text-danger mt-1">{{ form.errors.preferred_world }}</p>
                        </div>

                        <!-- Checkboxes -->
                        <div class="space-y-3">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input
                                    v-model="form.accept_terms"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-frame bg-surface accent-gold"
                                />
                                <span class="text-sm text-muted">
                                    Li e aceito os
                                    <a href="#" class="text-gold underline">termos de uso</a>
                                </span>
                            </label>
                            <p v-if="form.errors.accept_terms" class="text-xs text-danger ml-7">{{ form.errors.accept_terms }}</p>

                            <label class="flex items-start gap-3 cursor-pointer">
                                <input
                                    v-model="form.lgpd_consent"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-frame bg-surface accent-gold"
                                />
                                <span class="text-sm text-muted">
                                    Consinto com o
                                    <a href="#" class="text-gold underline">tratamento de dados (LGPD)</a>
                                </span>
                            </label>
                            <p v-if="form.errors.lgpd_consent" class="text-xs text-danger ml-7">{{ form.errors.lgpd_consent }}</p>
                        </div>

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            class="w-full"
                            :loading="form.processing"
                        >
                            Criar conta
                        </Button>
                    </form>

                    <p class="mt-6 text-center text-sm text-muted">
                        Já tem conta?
                        <Link :href="route('login')" class="text-gold hover:text-gold-light">
                            Entrar
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
