import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Reverb fala o protocolo Pusher, então o cliente é o pusher-js.
window.Pusher = Pusher

// O servidor Reverb ainda pode não estar no ar (dev/staging usam BROADCAST=log e
// as VITE_REVERB_* ficam vazias). Sem a key, NÃO instanciamos o Echo: evita o
// pusher-js ficar tentando abrir websocket e poluindo o console. Os componentes
// checam `window.Echo` antes de assinar canal — o chat degrada para "sem tempo
// real" (o histórico ainda carrega via Inertia) em vez de quebrar.
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY

window.Echo = reverbKey
    ? new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        // Canais privados autenticam em POST /broadcasting/auth pela SESSÃO web.
        // Precisa do CSRF (mesmo esquema do lib/http.js) senão o auth dá 419.
        auth: {
            headers: {
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    })
    : null
