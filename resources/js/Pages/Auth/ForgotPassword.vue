<script setup>
import { useForm, Link, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

const page = usePage()

const form = useForm({
    email: '',
})

function submit() {
    form.post(route('password.email'))
}
</script>

<template>
    <GuestLayout title="Esqueci minha senha">
        <div class="min-h-[80vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-sm">
                <div class="flex justify-center mb-8">
                    <PortalLogo :size="48" />
                </div>

                <div class="bg-surface border border-frame rounded-2xl p-8">
                    <h1 class="font-serif text-2xl text-cream mb-1">Esqueceu sua senha?</h1>
                    <p class="text-muted text-sm mb-8">
                        Informe seu e-mail e enviaremos um link para redefinir sua senha.
                    </p>

                    <div
                        v-if="page.props.flash?.success"
                        class="mb-6 bg-success/10 border border-success/30 rounded-xl p-4 text-sm text-success"
                    >
                        {{ page.props.flash.success }}
                    </div>

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

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            class="w-full"
                            :loading="form.processing"
                        >
                            Enviar link de redefinição
                        </Button>
                    </form>

                    <p class="mt-6 text-center text-sm text-muted">
                        Lembrou a senha?
                        <Link :href="route('login')" class="text-gold hover:text-gold-light">
                            Entrar
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
