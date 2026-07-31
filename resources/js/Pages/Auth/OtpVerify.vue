<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

const props = defineProps({
    // Vem da sessão (nunca da URL) — é o e-mail em verificação. O input fica
    // read-only para o usuário não trocar aqui: trocar de e-mail é voltar e
    // pedir outro código.
    email: { type: String, required: true },
    expiresInMinutes: { type: Number, default: 5 },
})

const form = useForm({
    email: props.email,
    code: '',
})

function submit() {
    form.post(route('otp.verify'), {
        onFinish: () => form.reset('code'),
    })
}
</script>

<template>
    <GuestLayout title="Verificar código">
        <div class="min-h-[80vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-sm">
                <div class="flex justify-center mb-8">
                    <PortalLogo :size="48" />
                </div>

                <div class="bg-surface border border-frame rounded-2xl p-8">
                    <h1 class="font-serif text-2xl text-cream mb-1">Digite o código</h1>
                    <p class="text-muted text-sm mb-8">
                        Enviamos um código de acesso para
                        <span class="text-cream">{{ email }}</span>.
                        Ele expira em {{ expiresInMinutes }} minutos.
                    </p>

                    <form @submit.prevent="submit" novalidate class="space-y-5">
                        <div class="flex flex-col gap-1.5">
                            <label for="code" class="text-sm font-medium text-cream">
                                Código de acesso
                                <span class="text-gold ml-0.5">*</span>
                            </label>
                            <input
                                id="code"
                                v-model="form.code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                placeholder="000000"
                                :class="[
                                    'w-full rounded-lg border bg-surface px-4 py-3 text-center font-mono text-2xl tracking-[0.5em] text-cream placeholder:text-muted placeholder:tracking-[0.5em]',
                                    'transition-colors duration-150',
                                    'focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold',
                                    form.errors.code ? 'border-danger' : 'border-frame',
                                ]"
                            />
                            <p v-if="form.errors.code" class="text-xs text-danger">{{ form.errors.code }}</p>
                        </div>

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
                        Não recebeu?
                        <Link :href="route('otp.request.show')" class="text-gold hover:text-gold-light">
                            Pedir um novo código
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
