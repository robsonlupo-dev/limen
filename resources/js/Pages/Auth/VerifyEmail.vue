<script setup>
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

const page = usePage()

function resend() {
    router.post(route('verification.resend'))
}
</script>

<template>
    <AppLayout title="Verificar e-mail">
        <div class="min-h-[70vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-md text-center space-y-6">
                <PortalLogo :size="56" />

                <div>
                    <h1 class="font-serif text-2xl text-cream mb-2">Verifique seu e-mail</h1>
                    <p class="text-muted text-sm leading-relaxed">
                        Enviamos um link de verificação para
                        <strong class="text-cream">{{ page.props.auth.user?.email }}</strong>.
                        Clique no link para ativar sua conta.
                    </p>
                </div>

                <div class="bg-surface border border-frame rounded-xl p-6 text-sm text-muted space-y-2">
                    <p>Não recebeu o e-mail?</p>
                    <p>Verifique sua caixa de spam ou solicite um novo link abaixo.</p>
                </div>

                <Button variant="ghost" @click="resend">
                    Reenviar e-mail de verificação
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
