<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

const props = defineProps({
    token: String,
    email: String,
})

const form = useForm({
    token: props.token,
    email: props.email ?? '',
    password: '',
    password_confirmation: '',
})

function submit() {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <GuestLayout title="Redefinir senha">
        <div class="min-h-[80vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-sm">
                <div class="flex justify-center mb-8">
                    <PortalLogo :size="48" />
                </div>

                <div class="bg-surface border border-frame rounded-2xl p-8">
                    <h1 class="font-serif text-2xl text-cream mb-1">Redefinir senha</h1>
                    <p class="text-muted text-sm mb-8">Escolha uma nova senha para sua conta.</p>

                    <form @submit.prevent="submit" novalidate class="space-y-5">
                        <Input
                            id="email"
                            v-model="form.email"
                            label="E-mail"
                            type="email"
                            autocomplete="email"
                            :required="true"
                            :error="form.errors.email"
                        />

                        <Input
                            id="password"
                            v-model="form.password"
                            label="Nova senha"
                            type="password"
                            placeholder="Mín. 8 caracteres, 1 maiúscula e 1 número"
                            autocomplete="new-password"
                            :required="true"
                            :error="form.errors.password"
                        />

                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            label="Confirmar nova senha"
                            type="password"
                            placeholder="Repita a nova senha"
                            autocomplete="new-password"
                            :required="true"
                            :error="form.errors.password_confirmation"
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            class="w-full"
                            :loading="form.processing"
                        >
                            Redefinir senha
                        </Button>
                    </form>

                    <p class="mt-6 text-center text-sm text-muted">
                        <Link :href="route('login')" class="text-gold hover:text-gold-light">
                            Voltar para o login
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
