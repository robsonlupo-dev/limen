<?php

use App\Models\PerformerContent;
use App\Models\Subscription;
use App\Services\ContentStore;
use App\Services\ContentUnlockService;
use App\Services\PerformerContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * fix/chat-ux-mobile: o card de conteúdo quebrava silenciosamente quando os bytes
 * da imagem faltavam no disco (arquivo não sincronizado entre clones, thumbnail não
 * gerado, upload que falhou). `ContentStore::retrieve` lançava → 500, e a <img>
 * mostrava o ícone de imagem quebrada com o alt cobrindo o selo de nível.
 *
 * Agora `content.image` devolve 404 DEFINIDO quando o arquivo não existe, e o front
 * (ContentGallery) cai num placeholder decente via @error. Reusa chatPerformer /
 * chatMember (tests/Pest.php).
 */

// Peça Premium publicada + membro Prestige que a desbloqueou → canView true, então
// o serving é exercitado (o membro alcança content.image).
function cifViewablePremium(): array
{
    $profile = chatPerformer();
    $content = app(PerformerContentService::class)->publish(
        $profile,
        UploadedFile::fake()->image('c.jpg', 400, 400),
        PerformerContent::LEVEL_PREMIUM,
        20,
    );

    $member = chatMember(50);
    Subscription::factory()->create(['user_id' => $member->id]); // prestige → alcança premium
    app(ContentUnlockService::class)->unlock($member, $content);

    return [$content, $member];
}

it('serve a imagem normalmente quando os bytes existem', function () {
    [$content, $member] = cifViewablePremium();

    $this->actingAs($member)
        ->get(route('content.image', $content->id))
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');
});

it('devolve 404 (não 500) quando o arquivo da imagem está ausente no disco', function () {
    [$content, $member] = cifViewablePremium();

    // Simula os bytes ausentes (o que ScreenshotDaBella expõe): apaga o arquivo,
    // mas a linha e o acesso continuam — canView segue true.
    app(ContentStore::class)->delete($content->path);

    $this->actingAs($member)
        ->get(route('content.image', $content->id))
        ->assertNotFound(); // 404 definido, não o 500 do retrieve()
});
