<script setup>
import { ref } from 'vue'
import Modal from './Modal.vue'
import Button from './Button.vue'
import PortalLogo from './PortalLogo.vue'

const props = defineProps({
    ageAccepted: { type: Boolean, default: false },
})

const accepted = ref(props.ageAccepted)

function accept() {
    const expires = new Date()
    expires.setFullYear(expires.getFullYear() + 1)
    const secure = window.location.protocol === 'https:' ? '; Secure' : ''
    document.cookie = `limen_age=1; path=/; expires=${expires.toUTCString()}; SameSite=Lax${secure}`
    accepted.value = true
}

function decline() {
    window.location.href = 'https://www.google.com'
}
</script>

<template>
    <Modal :show="!accepted" :max-width="'md'">
        <div class="flex flex-col items-center text-center gap-6">
            <PortalLogo :size="52" />

            <div>
                <h2 class="font-serif text-2xl text-cream mb-2">Acesso restrito</h2>
                <p class="text-muted text-sm leading-relaxed">
                    Este portal contém conteúdo exclusivo para adultos.<br>
                    Você confirma que tem <strong class="text-cream">18 anos ou mais</strong>?
                </p>
            </div>

            <div class="flex flex-col gap-3 w-full">
                <Button variant="primary" size="lg" class="w-full" @click="accept">
                    Sim, tenho 18 anos ou mais
                </Button>
                <Button variant="ghost" size="md" class="w-full" @click="decline">
                    Não, sair
                </Button>
            </div>

            <p class="text-xs text-muted">
                Ao entrar, você confirma ser maior de idade e aceita os
                <a href="#" class="text-gold underline">termos de uso</a>.
            </p>
        </div>
    </Modal>
</template>
