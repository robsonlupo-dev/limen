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
    password: '',
    captcha_token: '',
})

function submit() {
    form.post(route('login.store'), {
        // O token do captcha é de uso único: depois de uma tentativa recusada
        // (senha errada) ele está queimado, e sem rearmar o widget a segunda
        // tentativa falharia no captcha em vez de na senha.
        onError: () => captcha.value?.reset(),
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

                        <div class="text-right -mt-2">
                            <Link
                                :href="route('password.request')"
                                class="text-[13px] text-gold/70 hover:text-gold-light transition-colors"
                            >
                                Esqueceu sua senha?
                            </Link>
                        </div>

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
                            Entrar
                        </Button>
                    </form>

                    <div class="mt-6 flex items-center gap-3">
                        <span class="h-px flex-1 bg-frame" />
                        <span class="text-xs text-muted">ou</span>
                        <span class="h-px flex-1 bg-frame" />
                    </div>

                    <Link
                        :href="route('otp.request.show')"
                        class="mt-6 block w-full rounded-lg border border-gold px-8 py-4 text-center text-base text-gold transition-colors hover:bg-gold/10"
                    >
                        Entrar com código por e-mail
                    </Link>

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
