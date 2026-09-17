<?php

use Illuminate\Support\Facades\File;

// Selo de curadoria (Maison/Select) ao lado do nome no perfil da performer.
// Readicionado após o redesign maison (que removeu o VerificationBadges e, com
// ele, o selo de curadoria). Verificado pela fonte .vue — o projeto não tem
// Vitest (mesma disciplina do PanicButton/UAT). Distinto do VerifiedBadge
// (verificação dourada), que permanece.

it('monta o selo de curadoria ao lado do nome no cabeçalho do perfil', function () {
    // feat/performer-profile-redesign: o cabeçalho dos dois perfis públicos (membro
    // e visitante) virou o ProfileHero compartilhado — o selo de curadoria mora
    // nele agora, ao lado do nome, uma vez só para as duas telas.
    $src = File::get(resource_path('js/Components/Profile/ProfileHero.vue'));

    expect($src)->toContain("import CurationSeal from '@/Components/CurationSeal.vue'")
        ->and($src)->toContain('<CurationSeal :tier="performer.tier" />');
});

it('estiliza Maison com borda e Select com fundo sutil, só em dourado', function () {
    $src = File::get(resource_path('js/Components/CurationSeal.vue'));

    // Só maison/select viram selo (tierBadgeLabel → null para os demais).
    expect($src)->toContain('tierBadgeLabel')
        ->and($src)->toContain("isMaison ? 'border border-limen-gold/60' : 'bg-limen-gold/15'")
        ->and($src)->toContain('text-limen-gold');
});
