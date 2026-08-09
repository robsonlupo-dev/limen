<script setup>
import { ref } from 'vue'
import { useForm, Link, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import Captcha from '@/Components/Captcha.vue'

// Desligado (o padrão) o widget nem monta, e o servidor não exige o campo.
const captchaConfig = usePage().props.captcha ?? { enabled: false, provider: null, sitekey: null }
const captcha = ref(null)

const form = useForm({
    email: '',
    captcha_token: '',
})

function submit() {
    form.post(route('otp.request'), {
        // O token do captcha é de uso único: se o servidor recusar (teto de
        // envios), rearma o widget para a próxima tentativa não falhar no
        // captcha em vez de na regra.
        onError: () => captcha.value?.reset(),
    })
}
</script>

<template>
    <GuestLayout title="Entrar com código">
        <div class="min-h-[80vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-sm">
                <div class="flex justify-center mb-8">
                    <PortalLogo :size="48" />
                </div>

                <div class="bg-surface border border-frame rounded-2xl p-8">
                    <h1 class="font-serif text-2xl text-cream mb-1">Entrar com código</h1>
                    <p class="text-muted text-sm mb-8">
                        Informe seu e-mail e enviaremos um código de acesso de uso único.
                    </p>

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

                        <div v-if="captchaConfig.enabled">
                            <Captcha
                                ref="captcha"
                                :provider="captchaConfig.provider"
                                :sitekey="captchaConfig.sitekey"
                                v-model="form.captcha_token"
                            />
                            <p v-if="form.errors.captcha_token" class="pt-1 text-xs text-danger">
                                {{ form.errors.captcha_token }}
                            </p>
                        </div>

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            class="w-full"
                            :loading="form.processing"
                        >
                            Enviar código
                        </Button>
                    </form>

                    <p class="mt-6 text-center text-sm text-muted">
                        Prefere sua senha?
                        <Link :href="route('login')" class="text-gold hover:text-gold-light">
                            Entrar com senha
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
