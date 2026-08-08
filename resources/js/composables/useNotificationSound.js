import { usePage } from '@inertiajs/vue3'
import { playNotificationSound } from '@/lib/notificationSound'

/**
 * Gate de preferência do som de notificação (Sprint 16). Lê o estado efetivo
 * que o backend já resolveu (User::notificationSoundPreferences → props
 * auth.user.notification_preferences, "ausente = ON") e só toca quando a
 * categoria não está explicitamente desligada.
 *
 * Uso: `const { play } = useNotificationSound()` no setup do componente que
 * escuta o evento (MessageToast, LiveOverlay, CallIncoming) e `play('message')`
 * no handler. A síntese em si (Web Audio) e a falha silenciosa vivem em
 * @/lib/notificationSound.
 */
export function useNotificationSound() {
    const page = usePage()

    function play(kind) {
        const prefs = page.props?.auth?.user?.notification_preferences ?? {}
        // `!== false`: ausente/indefinido continua tocando (mesmo default do
        // backend), só o false explícito silencia.
        if (prefs[kind] === false) return
        playNotificationSound(kind)
    }

    return { play }
}
