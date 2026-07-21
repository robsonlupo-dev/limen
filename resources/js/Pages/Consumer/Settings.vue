<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DiscreteModeToggle from '@/Components/DiscreteModeToggle.vue'
import PrivacyPerkToggle from '@/Components/PrivacyPerkToggle.vue'
import AccountDeletionSection from '@/Components/AccountDeletionSection.vue'

defineProps({
    deletion: { type: Object, default: () => ({}) },
})

const page = usePage()
const canUseDiscreteMode = computed(() => page.props.auth?.user?.can_use_discrete_mode === true)
</script>

<template>
    <AppLayout title="Configurações">
        <div class="max-w-2xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Configurações</h1>
                    <p class="text-muted text-sm">Privacidade e preferências da sua conta.</p>
                </div>
                <Link :href="route('consumer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <div class="space-y-3">
                <DiscreteModeToggle />

                <!-- Sem o perk a tela ficaria vazia: explica em vez de sumir. -->
                <div v-if="!canUseDiscreteMode" class="rounded-xl border border-frame bg-surface p-5 space-y-1">
                    <p class="text-cream font-medium">Modo Discreto</p>
                    <p class="text-muted text-sm">
                        Disponível para membros Black e Founders Circle. Sua presença some da lista de
                        seguidores das performers, mantendo você invisível para elas.
                    </p>
                    <Link :href="route('subscribe.index')" class="inline-block pt-1 text-sm text-gold hover:text-gold-light transition-colors">
                        Ver Círculos &rarr;
                    </Link>
                </div>

                <PrivacyPerkToggle
                    perk="ghost_mode"
                    title="Ghost Mode"
                    description="Suas visitas a perfis não são registradas"
                    detail="A performer não vê você na lista de visitantes recentes do painel dela."
                />

                <PrivacyPerkToggle
                    perk="invisible_status"
                    title="Status Invisível"
                    description="Sua presença não é exposta a ninguém"
                    detail="Você navega sem que performers ou outros membros saibam que você está online."
                />

                <!-- Invertido: o membro liga a PRIVACIDADE, e no banco isso é
                     read_receipts_enabled = false. -->
                <PrivacyPerkToggle
                    perk="read_receipts_enabled"
                    title="Confirmação de leitura desligada"
                    description="Ninguém sabe se você leu a mensagem"
                    detail="Você continua vendo quando suas próprias mensagens são lidas por quem não desligou."
                    inverted
                />
            </div>

            <!-- Última seção da tela de propósito: ação destrutiva não disputa
                 atenção com preferência de uso. -->
            <AccountDeletionSection :deletion="deletion" />
        </div>
    </AppLayout>
</template>
