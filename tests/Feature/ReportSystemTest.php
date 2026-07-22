<?php

use App\Mail\ReportReceivedMail;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Canal mínimo de denúncia (compliance legal). Sem ele um membro que vê
 * conteúdo ilegal não tem caminho para reportar — e a plataforma não tem prova
 * de ter sido notificada.
 *
 * Helpers locais (prefixo report*) para o arquivo rodar isolado ou na suíte.
 */
function reportMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function reportPerformerProfile(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function reportPayload(PerformerProfile $profile, string $reason = 'underage_content'): array
{
    return [
        'reportable_type' => 'performer',
        'reportable_id' => $profile->id,
        'reason' => $reason,
        'details' => 'Parece muito nova nas fotos do perfil.',
    ];
}

it('lets a member report a performer profile', function () {
    Mail::fake();

    $member = reportMember();
    $profile = reportPerformerProfile();

    $this->actingAs($member)
        ->postJson(route('report.store'), reportPayload($profile))
        ->assertOk()
        ->assertJsonFragment(['message' => 'Denúncia recebida. Nossa equipe vai analisar.']);

    $this->assertDatabaseHas('reports', [
        'reporter_id' => $member->id,
        'reportable_type' => PerformerProfile::class,
        'reportable_id' => $profile->id,
        'reason' => 'underage_content',
        'status' => 'pending',
        'reviewed_by' => null,
        'reviewed_at' => null,
    ]);
});

it('rejects a report against the reporter themselves', function () {
    $profile = reportPerformerProfile();

    // A dona do perfil tentando denunciar o próprio perfil.
    $this->actingAs($profile->user)
        ->postJson(route('report.store'), reportPayload($profile))
        ->assertStatus(422)
        ->assertJsonFragment(['reason' => 'self_report']);

    expect(Report::count())->toBe(0);
});

it('blocks a second report of the same target and reason within 24h', function () {
    Mail::fake();

    $member = reportMember();
    $profile = reportPerformerProfile();

    $this->actingAs($member)
        ->postJson(route('report.store'), reportPayload($profile))
        ->assertOk();

    // Repetição dentro da janela: responde como sucesso (não confirma ao
    // denunciante o estado anterior) mas NÃO grava uma segunda linha.
    $this->actingAs($member)
        ->postJson(route('report.store'), reportPayload($profile))
        ->assertOk();

    expect(Report::count())->toBe(1);

    // Motivo diferente é outra denúncia — a janela é por (alvo, motivo).
    $this->actingAs($member)
        ->postJson(route('report.store'), reportPayload($profile, 'spam'))
        ->assertOk();

    expect(Report::count())->toBe(2);
});

it('allows the same target and reason again once the window has passed', function () {
    Mail::fake();

    $member = reportMember();
    $profile = reportPerformerProfile();

    $this->actingAs($member)
        ->postJson(route('report.store'), reportPayload($profile))
        ->assertOk();

    $this->travel(Report::DEDUP_WINDOW_HOURS + 1)->hours();

    $this->actingAs($member)
        ->postJson(route('report.store'), reportPayload($profile))
        ->assertOk();

    expect(Report::count())->toBe(2);
});

it('queues the admin notification after a report', function () {
    Mail::fake();
    config(['mail.admin_address' => 'admin@thelimen.com.br']);

    $member = reportMember();
    $profile = reportPerformerProfile();

    $this->actingAs($member)
        ->postJson(route('report.store'), reportPayload($profile))
        ->assertOk();

    Mail::assertQueued(
        ReportReceivedMail::class,
        fn (ReportReceivedMail $mail) => $mail->hasTo('admin@thelimen.com.br')
            && $mail->report->id === Report::first()->id,
    );
});

it('keeps the reporter identity and the free-text body out of the alert email', function () {
    $member = reportMember();
    $profile = reportPerformerProfile();
    $report = Report::open($member, $profile, 'coercion', 'SEGREDO-DO-DENUNCIANTE');

    $rendered = (new ReportReceivedMail($report))->render();

    expect($rendered)->not->toContain('SEGREDO-DO-DENUNCIANTE')
        ->and($rendered)->not->toContain($member->email);
});

it('rejects an unknown reportable type', function () {
    $member = reportMember();

    $this->actingAs($member)
        ->postJson(route('report.store'), [
            'reportable_type' => 'App\\Models\\IdentityVerification',
            'reportable_id' => 1,
            'reason' => 'spam',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reportable_type']);

    expect(Report::count())->toBe(0);
});

it('rejects a report against a target that does not exist', function () {
    $member = reportMember();

    $this->actingAs($member)
        ->postJson(route('report.store'), [
            'reportable_type' => 'performer',
            'reportable_id' => 999999,
            'reason' => 'spam',
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['reason' => 'target_not_found']);

    expect(Report::count())->toBe(0);
});

/**
 * Denunciar exige ter visto. Sem esta porta, variar o reportable_id no POST
 * separaria "existe" de "não existe" e enumeraria as mensagens de conversas
 * alheias — e ainda direcionaria a moderação a abrir chat privado de terceiros
 * a pedido de um estranho (assédio por procuração).
 */
it('does not let a member report a message from a conversation they are not in', function () {
    $performer = reportPerformerProfile();
    $insider = reportMember();
    $outsider = reportMember();

    $conversation = Conversation::create([
        'member_id' => $insider->id,
        'performer_profile_id' => $performer->id,
    ]);

    $message = new Message(['conversation_id' => $conversation->id, 'body' => 'oi']);
    $message->forceFill(['sender_id' => $performer->user_id])->save();

    // Mesma resposta que um id inexistente: nada distingue os dois casos.
    $this->actingAs($outsider)
        ->postJson(route('report.store'), [
            'reportable_type' => 'message',
            'reportable_id' => $message->id,
            'reason' => 'coercion',
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['reason' => 'target_not_found']);

    expect(Report::count())->toBe(0);

    // O participante, esse sim, denuncia.
    Mail::fake();

    $this->actingAs($insider)
        ->postJson(route('report.store'), [
            'reportable_type' => 'message',
            'reportable_id' => $message->id,
            'reason' => 'coercion',
        ])
        ->assertOk();

    expect(Report::count())->toBe(1);
});

it('gives the same answer for a missing target and one the reporter cannot see', function () {
    $outsider = reportMember();

    $missing = $this->actingAs($outsider)->postJson(route('report.store'), [
        'reportable_type' => 'message',
        'reportable_id' => 999999,
        'reason' => 'spam',
    ]);

    $performer = reportPerformerProfile();
    $conversation = Conversation::create([
        'member_id' => reportMember()->id,
        'performer_profile_id' => $performer->id,
    ]);
    $message = new Message(['conversation_id' => $conversation->id, 'body' => 'oi']);
    $message->forceFill(['sender_id' => $performer->user_id])->save();

    $hidden = $this->actingAs($outsider)->postJson(route('report.store'), [
        'reportable_type' => 'message',
        'reportable_id' => $message->id,
        'reason' => 'spam',
    ]);

    expect($hidden->status())->toBe($missing->status())
        ->and($hidden->json())->toBe($missing->json());
});

it('requires authentication to report', function () {
    $profile = reportPerformerProfile();

    // Redirect, não 401: shouldRenderJsonWhen só transforma exceção em JSON
    // dentro de api/* (bootstrap/app.php), então a rota web devolve o redirect
    // de login mesmo para um pedido com Accept: application/json.
    $this->postJson(route('report.store'), reportPayload($profile))
        ->assertRedirect(route('login'));

    expect(Report::count())->toBe(0);
});

it('shows pending reports to an admin', function () {
    $member = reportMember();
    $profile = reportPerformerProfile();
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    Report::open($member, $profile, 'underage_content', 'detalhe da denúncia');

    $response = $this->actingAs($admin)->get(route('admin.reports'));

    $response->assertOk()
        ->assertSee('underage_content')
        ->assertSee('detalhe da denúncia');

    // O denunciante nunca aparece cru na tela de moderação.
    $response->assertDontSee($member->email)
        ->assertSee('Denunciante #');
});

it('denies the admin report queue to non-admins', function () {
    $member = reportMember();
    $profile = reportPerformerProfile();

    $this->actingAs($member)->get(route('admin.reports'))->assertForbidden();
    $this->actingAs($profile->user)->get(route('admin.reports'))->assertForbidden();
    // Visitante também é barrado — 403 (e não o redirect de login) porque o
    // role:admin resolve antes; mesma resposta que /admin/waitlist já dá.
    $this->get(route('admin.reports'))->assertForbidden();
});

it('lets an admin resolve a report and records who reviewed it', function () {
    $member = reportMember();
    $profile = reportPerformerProfile();
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $report = Report::open($member, $profile, 'spam');

    $this->actingAs($admin)
        ->patch(route('admin.reports.update', $report), ['status' => 'resolved'])
        ->assertRedirect();

    $report->refresh();

    expect($report->status)->toBe('resolved')
        ->and($report->reviewed_by)->toBe($admin->id)
        ->and($report->reviewed_at)->not->toBeNull();
});

it('does not let a non-admin change a report status', function () {
    $member = reportMember();
    $profile = reportPerformerProfile();

    $report = Report::open($member, $profile, 'spam');

    $this->actingAs($member)
        ->patch(route('admin.reports.update', $report), ['status' => 'dismissed'])
        ->assertForbidden();

    expect($report->fresh()->status)->toBe('pending');
});

it('ignores reviewer fields coming from the request payload', function () {
    Mail::fake();

    $member = reportMember();
    $profile = reportPerformerProfile();

    $this->actingAs($member)
        ->postJson(route('report.store'), reportPayload($profile) + [
            'status' => 'dismissed',
            'reviewed_by' => $member->id,
            'reviewed_at' => now()->toDateTimeString(),
        ])
        ->assertOk();

    $report = Report::first();

    expect($report->status)->toBe('pending')
        ->and($report->reviewed_by)->toBeNull()
        ->and($report->reviewed_at)->toBeNull();
});
