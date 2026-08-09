<script setup>
/**
 * Widget de captcha — hCaptcha OU Cloudflare Turnstile, conforme o provider.
 *
 * O SDK é carregado AQUI, dinamicamente, e não por uma `<script src>` no
 * app.blade.php. A diferença não é de estilo:
 *
 *   app.blade.php é a view raiz de TODA página Inertia. Um script de terceiro
 *   ali carregaria em catálogo, chat, carteira e painel da performer — cada
 *   tela logada viraria uma requisição ao provedor (hcaptcha.com ou
 *   challenges.cloudflare.com) com IP, User-Agent e horário do membro. É
 *   exatamente o que docs/PIXEL_AUDIT.md audita e proíbe ("zero pixels de
 *   terceiros em área logada"), e é o motivo de as fontes terem sido trazidas
 *   para self-host.
 *
 * Montado só nas telas de login e cadastro — públicas e deslogadas —, o contato
 * com o terceiro fica restrito ao momento da autenticação. O componente só é
 * renderizado quando há provider ativo (ver HandleInertiaRequests), então com o
 * captcha desligado (`CAPTCHA_PROVIDER=none`) nada é buscado.
 *
 * hCaptcha e Turnstile têm APIs quase idênticas: um objeto global com
 * `render(container, opts)` / `reset(id)` / `remove(id)`, e as MESMAS chaves de
 * opção (`sitekey`, `theme`, `callback`, `expired-callback`, `error-callback`).
 * Só mudam o objeto global e a URL do SDK.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
    // 'hcaptcha' | 'turnstile'. Vem das props globais do Inertia (captcha.provider).
    provider: { type: String, default: 'hcaptcha' },
    sitekey: { type: String, required: true },
    theme: { type: String, default: 'dark' },
})

const emit = defineEmits(['update:modelValue'])

const container = ref(null)
const failed = ref(false)
let widgetId = null

const isTurnstile = props.provider === 'turnstile'

/** O objeto global do SDK ativo, ou undefined enquanto não carregou. */
function api() {
    return isTurnstile ? window.turnstile : window.hcaptcha
}

/**
 * Carrega o SDK do provedor ativo uma vez por documento.
 *
 * A promessa fica no `window` porque duas instâncias do componente na mesma
 * página (ou uma remontagem do Inertia, que não recarrega o documento) não
 * podem injetar dois `<script>` — o segundo redefine o global no meio da
 * renderização do primeiro widget. O callback `__limenCaptchaReady` é o
 * `onload` nomeado que os dois SDKs chamam quando o global existe.
 */
function loadSdk() {
    if (api()) return Promise.resolve()
    if (window.__limenCaptchaPromise) return window.__limenCaptchaPromise

    window.__limenCaptchaPromise = new Promise((resolve, reject) => {
        window.__limenCaptchaReady = resolve

        const script = document.createElement('script')

        // URL LITERAL por provedor, de propósito: a varredura de origem externa
        // (tests/Unit/ExternalAssetPolicyTest) só enxerga literal, e escondê-la
        // atrás de um mapa/constante faria o terceiro passar despercebido pela
        // auditoria que existe justamente para pegá-lo. `js.hcaptcha.com` e
        // `challenges.cloudflare.com` estão declarados em ALLOWED_JS_ORIGINS,
        // com o aval do PO. `render=explicit` para o SDK não varrer o DOM
        // sozinho — quem decide onde e quando renderizar é este componente.
        if (isTurnstile) {
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__limenCaptchaReady'
        } else {
            script.src = 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=__limenCaptchaReady'
        }
        script.async = true
        script.defer = true
        script.onerror = () => reject(new Error('Captcha SDK indisponível'))
        document.head.appendChild(script)
    })

    return window.__limenCaptchaPromise
}

onMounted(async () => {
    try {
        await loadSdk()
    } catch {
        // Provedor fora do ar. A tela avisa e o submit continua possível: o
        // servidor faz o mesmo fail-open em falha de REDE (ver
        // RemoteCaptchaDriver), senão uma queda do provedor trancaria o login.
        failed.value = true
        return
    }

    if (!container.value || !api()) return

    widgetId = api().render(container.value, {
        sitekey: props.sitekey,
        theme: props.theme,
        callback: (token) => emit('update:modelValue', token),
        // O token expira sozinho depois de alguns minutos. Zerar o v-model é o
        // que impede o form de mandar um token morto e o usuário levar
        // "verificação falhou" sem ter feito nada errado.
        'expired-callback': () => emit('update:modelValue', ''),
        'error-callback': () => emit('update:modelValue', ''),
    })
})

onBeforeUnmount(() => {
    if (widgetId !== null && api()) {
        // Sem o remove, navegar entre /login e /cadastro (Inertia não recarrega
        // o documento) deixa o widget antigo pendurado no SDK.
        try {
            api().remove(widgetId)
        } catch {
            // Widget já removido pelo próprio SDK — nada a desfazer.
        }
    }
})

/**
 * Rearma o desafio.
 *
 * O token é de USO ÚNICO. Depois de um submit recusado por qualquer motivo
 * (senha errada, e-mail já cadastrado), o token que foi junto está queimado:
 * sem este reset a segunda tentativa falharia no captcha, e a pessoa veria
 * "verificação de segurança falhou" quando o erro real era a senha.
 */
function reset() {
    emit('update:modelValue', '')

    if (widgetId !== null && api()) {
        try {
            api().reset(widgetId)
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
