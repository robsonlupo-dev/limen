<?php

use App\Models\ChatCatalogTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Mensagens de catálogo pré-cadastradas (feat/catalog-message-templates). Eixo:
 * (1) a migration semeia as 15 aprovadas; (2) o render resolve/limpa `{nome}`;
 * (3) o admin faz CRUD e só o admin alcança a tela.
 * O caminho de ENVIO (seletor em vez de texto livre) é coberto no
 * MemberEngagementTest.
 */

function cmtAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'status' => 'active']);
}

// ─── 1. Semente ────────────────────────────────────────────────────────────────

it('seeds the 15 approved templates in the migration', function () {
    expect(ChatCatalogTemplate::count())->toBe(15)
        ->and(ChatCatalogTemplate::where('is_active', true)->count())->toBe(15)
        ->and(ChatCatalogTemplate::activeOrdered()->first()->body)->toContain('fiquei curiosa');
});

// ─── 2. Render / personalização ─────────────────────────────────────────────────

it('replaces {nome} with the member nickname when present', function () {
    expect(ChatCatalogTemplate::render('Oi, {nome}! Tudo bem?', 'Ana'))->toBe('Oi, Ana! Tudo bem?')
        ->and(ChatCatalogTemplate::render('E aí {apelido}?', 'Bia'))->toBe('E aí Bia?');
});

it('strips {nome} cleanly when the member has no nickname', function () {
    // Sem apelido: o token some e a vírgula/espaço órfãos são limpos.
    expect(ChatCatalogTemplate::render('Oi, {nome}! Tudo bem?', null))->toBe('Oi! Tudo bem?')
        ->and(ChatCatalogTemplate::render('Oi, {nome}…', ''))->toBe('Oi…')
        ->and(ChatCatalogTemplate::render('Sem token aqui.', null))->toBe('Sem token aqui.');
});

// ─── 3. Admin CRUD ───────────────────────────────────────────────────────────────

it('lets an admin open the templates screen', function () {
    $this->actingAs(cmtAdmin())
        ->get(route('admin.catalog-messages'))
        ->assertOk()
        ->assertSee('Mensagens de catálogo');
});

it('lets an admin create, edit, toggle and delete a template', function () {
    $admin = cmtAdmin();

    // Criar.
    $this->actingAs($admin)
        ->post(route('admin.catalog-messages.store'), ['body' => 'Nova mensagem de teste'])
        ->assertRedirect(route('admin.catalog-messages'));
    $template = ChatCatalogTemplate::where('body', 'Nova mensagem de teste')->sole();
    expect($template->is_active)->toBeTrue();

    // Editar + desativar.
    $this->actingAs($admin)
        ->patch(route('admin.catalog-messages.update', $template), [
            'body' => 'Mensagem editada',
            'position' => 3,
            // is_active omitido = checkbox desmarcado → desativa
        ])
        ->assertRedirect(route('admin.catalog-messages'));
    $template->refresh();
    expect($template->body)->toBe('Mensagem editada')
        ->and($template->position)->toBe(3)
        ->and($template->is_active)->toBeFalse();

    // Remover.
    $this->actingAs($admin)
        ->delete(route('admin.catalog-messages.destroy', $template))
        ->assertRedirect(route('admin.catalog-messages'));
    expect(ChatCatalogTemplate::find($template->id))->toBeNull();
});

it('rejects an empty template body', function () {
    $this->actingAs(cmtAdmin())
        ->from(route('admin.catalog-messages'))
        ->post(route('admin.catalog-messages.store'), ['body' => ''])
        ->assertSessionHasErrors('body');
});

// ─── 4. Só admin alcança ─────────────────────────────────────────────────────────

it('denies the templates screen AND its write routes to non-admins', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'status' => 'active']);
    $template = ChatCatalogTemplate::activeOrdered()->first();

    $this->actingAs($user)->get(route('admin.catalog-messages'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.catalog-messages.store'), ['body' => 'x'])->assertForbidden();
    $this->actingAs($user)->patch(route('admin.catalog-messages.update', $template), ['body' => 'x'])->assertForbidden();
    $this->actingAs($user)->delete(route('admin.catalog-messages.destroy', $template))->assertForbidden();

    // Nada foi alterado pela tentativa do não-admin.
    expect(ChatCatalogTemplate::count())->toBe(15);
})->with(['consumer', 'performer', 'moderator']);

it('requires authentication for the templates screen', function () {
    $this->get(route('admin.catalog-messages'))->assertRedirect(route('login'));
});
