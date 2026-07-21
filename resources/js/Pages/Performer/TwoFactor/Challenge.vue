<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import Input from '@/Components/Input.vue'

const form = useForm({ code: '' })

function submit() {
    form.post(route('performer.2fa.verify'), {
        onFinish: () => form.reset('code'),
    })
}
</script>

<template>
    <AppLayout title="Verificação em duas etapas">
        <div class="max-w-md mx-auto px-6 py-16 space-y-6">
            <div class="space-y-2">
                <h1 class="font-serif text-3xl text-cream">Confirme que é você</h1>
                <p class="text-muted text-sm">
                    Digite o código de 6 dígitos do seu aplicativo autenticador. Se você não estiver
                    com o celular, use um dos seus códigos de recuperação.
                </p>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <Input
                    id="2fa-challenge-code"
                    v-model="form.code"
                    label="Código"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    :error="form.errors.code"
                    required
                />

                <Button type="submit" :disabled="form.processing" class="w-full">
                    Verificar
                </Button>
            </form>

            <!-- Saída para quem perdeu o autenticador E os códigos. É a rota de
                 logout normal: sem ela a pessoa fica presa nesta tela, já
                 autenticada, sem conseguir nem trocar de conta. -->
            <div class="border-t border-frame pt-4">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="text-sm text-muted hover:text-cream transition-colors"
                >
                    Sair da conta
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
