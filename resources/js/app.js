import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { route as ziggyRoute } from '../../vendor/tightenco/ziggy'
import './bootstrap'
import '../css/app.css'

// route() com URLs relativas: o staging é acessado por túnel em porta alternativa
// (ex.: limen.dev.br:8443) e URLs absolutas geradas para outra origem quebram os
// XHRs do Inertia. Caminhos relativos são imunes a host/porta.
const route = (name, params, absolute = false, config) =>
    ziggyRoute(name, params, absolute, config)
window.route = route

createInertiaApp({
    // Marca cada aba como "<página> · Limen". Idempotente: títulos que já citam a
    // marca (a headline de marketing da home, ou páginas públicas cujo título
    // server-side/OG já carrega o sufixo) passam intactos — nunca duplicamos.
    title: (title) => {
        if (!title) return 'Limen'
        return title.includes('Limen') ? title : `${title} · Limen`
    },
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
        app.config.globalProperties.route = route
        app.provide('route', route)
        app.use(plugin).mount(el)
    },
    progress: {
        color: '#C9A24B',
    },
})
