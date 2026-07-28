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
    password: '',
    'h-captcha-response': '',
})

function submit() {
    form.post(route('login.store'), {
        // O token do hCaptcha é de uso único: depois de uma tentativa recusada
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
