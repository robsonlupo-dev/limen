<script setup>
/**
 * Widget do hCaptcha.
 *
 * O SDK é carregado AQUI, dinamicamente, e não por uma `<script src>` no
 * app.blade.php. A diferença não é de estilo:
 *
 *   app.blade.php é a view raiz de TODA página Inertia. Um script de terceiro
 *   ali carregaria em catálogo, chat, carteira e painel da performer — cada
 *   tela logada viraria uma requisição a hcaptcha.com com IP, User-Agent e
 *   horário do membro. É exatamente o que docs/PIXEL_AUDIT.md audita e proíbe
 *   ("zero pixels de terceiros em área logada"), e é o motivo de as fontes
 *   terem sido trazidas para self-host.
 *
 * Montado só nas telas de login e cadastro — públicas e deslogadas —, o
 * contato com o terceiro fica restrito ao momento da autenticação, que é o
 * mínimo para o captcha existir. O componente só é renderizado quando
 * `hcaptcha.enabled` é true (ver HandleInertiaRequests), então com a feature
 * desligada nada é buscado.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
    sitekey: { type: String, required: true },
    theme: { type: String, default: 'dark' },
})

const emit = defineEmits(['update:modelValue'])

const container = ref(null)
const failed = ref(false)
let widgetId = null

/**
 * Carrega o SDK uma vez por documento.
 *
 * A promessa fica no `window` porque duas instâncias do componente na mesma
 * página (ou uma remontagem do Inertia, que não recarrega o documento) não
 * podem injetar dois `<script>` — o segundo redefine `window.hcaptcha` no meio
 * da renderização do primeiro widget.
 */
function loadSdk() {
    if (window.hcaptcha) return Promise.resolve()
    if (window.__limenHcaptchaPromise) return window.__limenHcaptchaPromise

    window.__limenHcaptchaPromise = new Promise((resolve, reject) => {
        window.__limenHcaptchaReady = resolve

        const script = document.createElement('script')
        // URL literal de propósito: a varredura de origem externa
        // (tests/Unit/ExternalAssetPolicyTest) só enxerga literal, e escondê-la
        // atrás de uma constante faria este terceiro passar despercebido pela
        // auditoria que existe justamente para pegá-lo. `js.hcaptcha.com` está
        // declarado em ALLOWED_JS_ORIGINS, com o aval do PO.
        //
        // `render=explicit` para o SDK não varrer o DOM sozinho — quem decide
        // onde e quando renderizar é este componente. O `onload` nomeado é como
        // a API do hCaptcha avisa que `window.hcaptcha` existe.
        script.src = 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=__limenHcaptchaReady'
        script.async = true
        script.defer = true
        script.onerror = () => reject(new Error('hCaptcha SDK indisponível'))
        document.head.appendChild(script)
    })

    return window.__limenHcaptchaPromise
}

onMounted(async () => {
    try {
        await loadSdk()
    } catch {
        // Provedor fora do ar. A tela avisa e o submit continua possível: o
        // servidor faz o mesmo fail-open em falha de REDE (ver
        // HCaptchaVerifier), senão uma queda do hCaptcha trancaria o login.
        failed.value = true
        return
    }

    if (!container.value || !window.hcaptcha) return

    widgetId = window.hcaptcha.render(container.value, {
        sitekey: props.sitekey,
        theme: props.theme,
        callback: (token) => emit('update:modelValue', token),
        // Token do hCaptcha expira sozinho depois de alguns minutos. Zerar o
        // v-model é o que impede o form de mandar um token morto e o usuário
        // levar "verificação falhou" sem ter feito nada errado.
        'expired-callback': () => emit('update:modelValue', ''),
        'error-callback': () => emit('update:modelValue', ''),
    })
})

onBeforeUnmount(() => {
    if (widgetId !== null && window.hcaptcha) {
        // Sem o remove, navegar entre /login e /cadastro (Inertia não recarrega
        // o documento) deixa o widget antigo pendurado no SDK.
        try {
            window.hcaptcha.remove(widgetId)
        } catch {
            // Widget já removido pelo próprio SDK — nada a desfazer.
        }
    }
})

/**
 * Rearma o desafio.
 *
 * O token do hCaptcha é de USO ÚNICO. Depois de um submit recusado por qualquer
 * motivo (senha errada, e-mail já cadastrado), o token que foi junto está
 * queimado: sem este reset a segunda tentativa falharia no captcha, e a pessoa
 * veria "verificação de segurança falhou" quando o erro real era a senha.
 */
function reset() {
    emit('update:modelValue', '')

    if (widgetId !== null && window.hcaptcha) {
        try {
            window.hcaptcha.reset(widgetId)
        } catch {
            // Sem widget vivo não há o que rearmar.
        }
    }
}

defineExpose({ reset })
</script>

<template>
    <div>
        <div ref="container" />
        <p v-if="failed" class="text-xs text-muted">
            Não foi possível carregar a verificação de segurança. Você ainda pode continuar.
        </p>
    </div>
</template>
