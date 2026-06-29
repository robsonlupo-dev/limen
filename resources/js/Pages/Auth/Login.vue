<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

const form = useForm({
    email: '',
    password: '',
})

function submit() {
    form.post(route('login.store'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <GuestLayout title="Entrar">
        <div class="min-h-[80vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-sm">
                <!-- Logo -->
                <div class="flex justify-center mb-8">
                    <PortalLogo :size="48" />
                </div>

                <div class="bg-surface border border-frame rounded-2xl p-8">
                    <h1 class="font-serif text-2xl text-cream mb-1">Entrar</h1>
                    <p class="text-muted text-sm mb-8">Bem-vindo de volta ao portal.</p>

                    <form @submit.prevent="submit" novalidate class="space-y-5">
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
                            placeholder="Sua senha"
                            autocomplete="current-password"
                            :required="true"
                            :error="form.errors.password"
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            class="w-full"
                            :loading="form.processing"
                        >
                            Entrar
                        </Button>
                    </form>

                    <p class="mt-6 text-center text-sm text-muted">
                        Não tem conta?
                        <Link :href="route('register')" class="text-gold hover:text-gold-light">
                            Criar conta
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
