<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    birthdate: '',
    accept_terms: false,
    lgpd_consent: false,
})

function submit() {
    form.post(route('register.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <GuestLayout title="Criar conta">
        <div class="min-h-[80vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-md">
                <!-- Logo -->
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
