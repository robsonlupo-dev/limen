<script setup>
import { ref } from 'vue'
import { useForm, Link, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import HCaptcha from '@/Components/HCaptcha.vue'

// Desligado (o padrão) o widget nem monta, e o servidor não exige o campo.
const hcaptcha = usePage().props.hcaptcha ?? { enabled: false, sitekey: null }
const captcha = ref(null)

const form = useForm({
    email: '',
    'h-captcha-response': '',
})

function submit() {
    form.post(route('otp.request'), {
        // O token do hCaptcha é de uso único: se o servidor recusar (teto de
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

                        <div v-if="hcaptcha.enabled">
                            <HCaptcha
                                ref="captcha"
                                :sitekey="hcaptcha.sitekey"
                                v-model="form['h-captcha-response']"
                            />
                            <p v-if="form.errors['h-captcha-response']" class="pt-1 text-xs text-danger">
                                {{ form.errors['h-captcha-response'] }}
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
