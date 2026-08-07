<?php

use Illuminate\Support\Facades\File;

// Selo de curadoria (Maison/Select) ao lado do nome no perfil da performer.
// Readicionado após o redesign maison (que removeu o VerificationBadges e, com
// ele, o selo de curadoria). Verificado pela fonte .vue — o projeto não tem
// Vitest (mesma disciplina do PanicButton/UAT). Distinto do VerifiedBadge
// (verificação dourada), que permanece.

it('monta o selo de curadoria ao lado do nome nas duas páginas de perfil', function () {
    foreach (['Catalog/Show.vue', 'Performers/Show.vue'] as $page) {
        $src = File::get(resource_path("js/Pages/{$page}"));

        expect($src)->toContain("import CurationSeal from '@/Components/CurationSeal.vue'")
            ->and($src)->toContain('<CurationSeal :tier="performer.tier" />');
    }
});

it('estiliza Maison com borda e Select com fundo sutil, só em dourado', function () {
    $src = File::get(resource_path('js/Components/CurationSeal.vue'));

    // Só maison/select viram selo (tierBadgeLabel → null para os demais).
    expect($src)->toContain('tierBadgeLabel')
        ->and($src)->toContain("isMaison ? 'border border-limen-gold/60' : 'bg-limen-gold/15'")
        ->and($src)->toContain('text-limen-gold');
});
