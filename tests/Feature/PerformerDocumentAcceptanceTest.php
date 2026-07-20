<?php

use App\Models\DocumentAcceptance;
use App\Models\User;
use App\Support\ClientFingerprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Aceite da Política de Conteúdo Proibido + Contrato de Performance.
 *
 * A factory de performer já aceita os documentos por padrão (ver UserFactory),
 * então quem quer o estado "sem aceite" usa ->withoutDocumentAcceptances().
 */
function performerWithoutDocs(array $attributes = []): User
{
    return User::factory()
        ->performer()
        ->withoutDocumentAcceptances()
        ->create(['status' => 'active'] + $attributes);
}

/** Performer em dia com os documentos (default da factory) e com perfil. */
function activePerformerWithProfile(): User
{
    $user = User::factory()->performer()->create(['status' => 'active']);

    $user->performerProfile()->create([
        'stage_name' => 'Doc Performer',
        'slug' => 'doc-'.Str::random(6),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);

    return $user;
}

it('barra performer sem aceite no dashboard', function () {
    $performer = performerWithoutDocs();

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertRedirect(route('performer.documents'));
});

it('barra performer sem aceite nas demais telas da área', function (string $routeName) {
    $performer = performerWithoutDocs();

    $this->actingAs($performer)
        ->get(route($routeName))
        ->assertRedirect(route('performer.documents'));
})->with([
    'performer.onboarding',
    'performer.followers',
    'performer.interests.index',
    'performer.payouts.index',
    'performer.profile.edit',
]);

it('deixa performer com aceite vigente entrar no dashboard', function () {
    $performer = activePerformerWithProfile();

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertOk();
});

it('recusa editar um aceite já gravado', function () {
    $performer = User::factory()->performer()->create(['status' => 'active']);
    $row = $performer->documentAcceptances()->first();

    expect(fn () => $row->update(['document_version' => '1999-01-01']))
        ->toThrow(RuntimeException::class);
});

it('recusa gravar aceite se a versão não estiver configurada', function () {
    config()->set('documents.versions.content_policy', '');

    expect(fn () => DocumentAcceptance::currentVersion(DocumentAcceptance::TYPE_CONTENT_POLICY))
        ->toThrow(RuntimeException::class);
});

it('não interfere no membro', function () {
    // O middleware está no grupo da performer; o membro que caísse ali tem que
    // continuar barrado pelo role/gate de sempre — 403, nunca o redirect de
    // aceite (que vazaria a existência da tela para quem não é performer).
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($member)
        ->get(route('performer.dashboard'))
        ->assertForbidden();
});

it('exige re-aceite quando a versão do documento é bumpada', function () {
    $performer = activePerformerWithProfile();

    $this->actingAs($performer)->get(route('performer.dashboard'))->assertOk();

    // O escritório entrega o texto novo; o config aponta para a versão nova.
    config()->set('documents.versions.content_policy', '2026-09-01');

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertRedirect(route('performer.documents'));
});

it('grava um aceite por documento com a versão vigente', function () {
    $performer = performerWithoutDocs();

    $this->actingAs($performer)
        ->post(route('performer.documents.accept'), [
            'content_policy' => true,
            'performance_contract' => true,
        ])
        ->assertRedirect(route('performer.dashboard'));

    expect($performer->documentAcceptances()->count())->toBe(2);

    foreach (DocumentAcceptance::REQUIRED as $type) {
        $row = $performer->documentAcceptances()->where('document_type', $type)->sole();

        expect($row->document_version)->toBe(config("documents.versions.{$type}"))
            ->and($row->accepted_at)->not->toBeNull();
    }
});

it('grava ip_address_hash e user_agent_hash, nunca os valores crus', function () {
    $performer = performerWithoutDocs();

    $this->actingAs($performer)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', 'LimenTest/1.0')
        ->post(route('performer.documents.accept'), [
            'content_policy' => true,
            'performance_contract' => true,
        ]);

    $row = $performer->documentAcceptances()->first();

    expect($row->ip_address_hash)->toBe(ClientFingerprint::hash('203.0.113.7'))
        ->and($row->user_agent_hash)->toBe(ClientFingerprint::hash('LimenTest/1.0'))
        // Digest, não o valor: o IP não pode ser lido de volta do banco.
        ->and($row->ip_address_hash)->not->toContain('203.0.113.7')
        ->and($row->user_agent_hash)->not->toContain('LimenTest')
        ->and(strlen($row->ip_address_hash))->toBe(64);
});

it('recusa o aceite se algum checkbox não vier marcado', function (array $payload) {
    $performer = performerWithoutDocs();

    $this->actingAs($performer)
        ->from(route('performer.documents'))
        ->post(route('performer.documents.accept'), $payload)
        ->assertSessionHasErrors();

    expect($performer->documentAcceptances()->count())->toBe(0);
})->with([
    'só a política' => [['content_policy' => true]],
    'só o contrato' => [['performance_contract' => true]],
    'nenhum' => [[]],
    'recusa explícita' => [['content_policy' => false, 'performance_contract' => false]],
]);

it('é idempotente: re-aceitar a mesma versão não empilha linhas', function () {
    $performer = performerWithoutDocs();
    $payload = ['content_policy' => true, 'performance_contract' => true];

    $this->actingAs($performer)->post(route('performer.documents.accept'), $payload);
    $first = $performer->documentAcceptances()->orderBy('id')->first();

    $this->travel(1)->days();
    $this->actingAs($performer)->post(route('performer.documents.accept'), $payload);

    expect($performer->documentAcceptances()->count())->toBe(2)
        // A data com valor jurídico é a do primeiro aceite daquela versão.
        ->and($performer->documentAcceptances()->orderBy('id')->first()->accepted_at->timestamp)
        ->toBe($first->accepted_at->timestamp);
});

it('mantém o aceite antigo como histórico ao aceitar a versão nova', function () {
    $performer = User::factory()->performer()->create(['status' => 'active']);

    config()->set('documents.versions.content_policy', '2026-09-01');

    $this->actingAs($performer)->post(route('performer.documents.accept'), [
        'content_policy' => true,
        'performance_contract' => true,
    ]);

    $versions = $performer->documentAcceptances()
        ->where('document_type', DocumentAcceptance::TYPE_CONTENT_POLICY)
        ->pluck('document_version')
        ->all();

    expect($versions)->toHaveCount(2)
        ->and($versions)->toContain('2026-07-20')
        ->and($versions)->toContain('2026-09-01');
});

it('não deixa o cliente escolher a versão aceita', function () {
    $performer = performerWithoutDocs();

    $this->actingAs($performer)->post(route('performer.documents.accept'), [
        'content_policy' => true,
        'performance_contract' => true,
        // Tentativa de satisfazer o middleware sem ter visto o texto vigente.
        'document_version' => '1999-01-01',
    ]);

    expect($performer->documentAcceptances()->pluck('document_version')->unique()->all())
        ->toBe(['2026-07-20']);
});

it('a tela de aceite é alcançável mesmo sem aceite (senão o redirect dá loop)', function () {
    $performer = performerWithoutDocs(['status' => 'pending']);

    $this->actingAs($performer)
        ->get(route('performer.documents'))
        ->assertOk();
});

it('serve os textos jurídicos publicamente com o placeholder', function (string $routeName) {
    $this->get(route($routeName))->assertOk();
})->with(['legal.content-policy', 'legal.performance-contract']);

it('document_acceptances não guarda CPF nem PII em texto puro', function () {
    $columns = Schema::getColumnListing('document_acceptances');

    foreach ($columns as $column) {
        expect($column)->not->toContain('cpf');
    }

    // O IP/UA só existem na forma de digest — nenhuma coluna crua.
    expect($columns)->toBe([
        'id', 'user_id', 'document_type', 'document_version',
        'accepted_at', 'ip_address_hash', 'user_agent_hash',
        'created_at', 'updated_at',
    ]);
});
