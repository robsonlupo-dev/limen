<?php

use App\Mail\AccountDeletionCancelledMail;
use App\Mail\AccountDeletionRequestedMail;
use App\Models\AuditLog;
use App\Models\DeletionLog;
use App\Models\Follow;
use App\Models\IdentityVerification;
use App\Models\Payout;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\Kyc\KycDocumentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Direito de eliminação (LGPD art. 18, VI) com carência de 30 dias.
 *
 * O eixo dos testes não é "apagou tudo" — é a fronteira entre as três
 * categorias do DeletionService: o que some, o que é anonimizado por desvínculo
 * e o que a lei obriga a preservar. Um teste que só verificasse a destruição
 * passaria feliz com uma implementação que apaga a prova de uma denúncia de
 * conteúdo ilegal junto.
 *
 * Helpers com prefixo del* para o arquivo rodar isolado ou na suíte.
 */

function delService(): DeletionService
{
    return app(DeletionService::class);
}

function delMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function delPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    $user->performerProfile()->create([
        'stage_name' => 'Perf ' . Str::random(4),
        'slug' => 'perf-' . strtolower(Str::random(6)),
        'category' => 'mulheres',
        'level' => 'iniciante',
        'split_pct' => 65,
        'is_verified' => true,
    ]);

    return $user->fresh();
}

function delPayout(User $performer, string $status): Payout
{
    return Payout::create([
        'performer_id' => $performer->id,
        'tokens' => 1000,
        'amount_brl' => '500.00',
        'pix_key' => '52998224725',
        'pix_key_type' => 'cpf',
        'status' => $status,
        'requested_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// Pedido
// ---------------------------------------------------------------------------

it('schedules deletion 30 days out and emails the holder', function () {
    Mail::fake();

    $user = delMember();

    delService()->requestDeletion($user);

    $user->refresh();

    expect($user->deletion_requested_at)->not->toBeNull()
        ->and($user->deletion_scheduled_at->toDateString())
        ->toBe(now()->addDays(30)->toDateString())
        ->and($user->deleted_at)->toBeNull();

    Mail::assertQueued(AccountDeletionRequestedMail::class);

    // O log nasce no pedido, sem executed_at: é a prova da DATA do pedido, que
    // é de onde corre o prazo legal.
    $log = DeletionLog::where('user_id', $user->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->reason)->toBe('user_request')
        ->and($log->executed_at)->toBeNull();
});

it('does not restart the LGPD clock when deletion is requested twice', function () {
    Mail::fake();

    $user = delMember();

    delService()->requestDeletion($user);
    $firstSchedule = $user->fresh()->deletion_scheduled_at;

    $this->travel(5)->days();
    delService()->requestDeletion($user);

    expect($user->fresh()->deletion_scheduled_at->toDateTimeString())
        ->toBe($firstSchedule->toDateTimeString())
        ->and(DeletionLog::where('user_id', $user->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Cancelamento
// ---------------------------------------------------------------------------

it('cancels the request inside the grace period', function () {
    Mail::fake();

    $user = delMember();
    delService()->requestDeletion($user);

    $this->travel(10)->days();

    expect(delService()->cancelDeletion($user->fresh()))->toBeTrue();

    $user->refresh();

    expect($user->deletion_requested_at)->toBeNull()
        ->and($user->deletion_scheduled_at)->toBeNull()
        ->and($user->deletion_token_hash)->toBeNull()
        ->and($user->deleted_at)->toBeNull()
        // O log do pedido desfeito sai — ver cancelDeletion().
        ->and(DeletionLog::where('user_id', $user->id)->count())->toBe(0);
});

it('refuses to cancel once the deletion has been executed', function () {
    Mail::fake();

    $user = delMember();
    delService()->requestDeletion($user);

    $this->travel(31)->days();
    delService()->executeDeletion($user->fresh());

    // Reconsultado sem o global scope: o user já está soft-deletado.
    $deleted = User::withTrashed()->find($user->id);

    expect(delService()->cancelDeletion($deleted))->toBeFalse()
        ->and(User::withTrashed()->find($user->id)->deleted_at)->not->toBeNull()
        // O log executado é intocável — é a prova de conformidade.
        ->and(DeletionLog::where('user_id', $user->id)->whereNotNull('executed_at')->count())
        ->toBe(1);
});

it('returns false when there is nothing to cancel', function () {
    expect(delService()->cancelDeletion(delMember()))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Execução: o que some, o que fica
// ---------------------------------------------------------------------------

it('wipes PII from the user row and closes the account by soft delete', function () {
    $user = delMember();
    $originalEmail = $user->email;

    delService()->executeDeletion($user);

    $row = DB::table('users')->where('id', $user->id)->first();

    expect($row->name)->toBe('[removido]')
        ->and($row->email)->not->toBe($originalEmail)
        ->and($row->email)->toContain('@deleted.invalid')
        ->and($row->phone)->toBeNull()
        ->and($row->birthdate)->toBeNull()
        ->and($row->remember_token)->toBeNull()
        ->and($row->deleted_at)->not->toBeNull();
});

it('preserves audit_logs and payouts with their amounts', function () {
    $performer = delPerformer();

    // Payout já pago: obrigação fiscal, não bloqueia a exclusão.
    $payout = delPayout($performer, 'paid');

    AuditLog::create([
        'user_id' => $performer->id,
        'action' => 'login',
        'ip' => '203.0.113.10',
    ]);

    delService()->executeDeletion($performer);

    // O login antigo sobrevive; a própria exclusão acrescenta a sua linha —
    // audit_logs é obrigação legal de trilha e nunca encolhe.
    expect(AuditLog::where('user_id', $performer->id)->where('action', 'login')->count())->toBe(1)
        ->and(AuditLog::where('user_id', $performer->id)->where('action', 'account.deletion_executed')->count())
        ->toBe(1);

    $payout->refresh();

    expect($payout->exists)->toBeTrue()
        ->and((float) $payout->amount_brl)->toBe(500.0)
        ->and($payout->tokens)->toBe(1000)
        // A chave PIX é dado de pagamento do titular: sai. O valor fica.
        ->and($payout->pix_key)->toBe('[removido]');
});

it('preserves reports filed by the user as the compliance trail', function () {
    $member = delMember();
    $profile = delPerformer()->performerProfile;

    DB::table('reports')->insert([
        'reporter_id' => $member->id,
        'reportable_type' => $profile->getMorphClass(),
        'reportable_id' => $profile->id,
        'reason' => 'underage_content',
        'details' => 'Denúncia de teste.',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    delService()->executeDeletion($member);

    // A denúncia continua de pé; quem denunciou é que virou '[removido]'.
    expect(DB::table('reports')->where('reporter_id', $member->id)->count())->toBe(1);
});

it('destroys KYC rows and their encrypted files on disk', function () {
    Storage::fake('kyc');

    $performer = delPerformer();

    $store = app(KycDocumentStore::class);
    $front = $store->store($performer->id, UploadedFile::fake()->image('front.jpg'), 'front');
    $selfie = $store->store($performer->id, UploadedFile::fake()->image('selfie.jpg'), 'selfie');

    IdentityVerification::create([
        'user_id' => $performer->id,
        'document_type' => 'rg',
        'document_number' => '52998224725',
        'full_legal_name' => 'Maria Teste Silva',
        'date_of_birth' => '1998-01-01',
        'document_front_path' => $front,
        'selfie_path' => $selfie,
        'status' => 'approved',
    ]);

    Storage::disk('kyc')->assertExists($front);

    delService()->executeDeletion($performer);

    Storage::disk('kyc')->assertMissing($front);
    Storage::disk('kyc')->assertMissing($selfie);

    expect(IdentityVerification::where('user_id', $performer->id)->count())->toBe(0);
});

it('drops follows and decrements the follower count that feeds the anonymity floor', function () {
    $member = delMember();
    $profile = delPerformer()->performerProfile;

    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
    $profile->increment('followers_count');

    delService()->executeDeletion($member);

    expect(Follow::where('user_id', $member->id)->count())->toBe(0)
        ->and($profile->fresh()->followers_count)->toBe(0);
});

it('anonymizes the performer profile instead of deleting it', function () {
    $performer = delPerformer();
    $profileId = $performer->performerProfile->id;

    delService()->executeDeletion($performer);

    // Hard delete é impossível aqui: tips/conversations/interests apontam para
    // o perfil com restrictOnDelete. A linha fica, esvaziada e soft-deletada.
    $profile = PerformerProfile::withTrashed()->find($profileId);

    expect($profile)->not->toBeNull()
        ->and($profile->stage_name)->toBe('[removido] #'.$profileId)
        ->and($profile->bio)->toBeNull()
        ->and($profile->avatar_path)->toBeNull()
        ->and($profile->is_verified)->toBeFalse()
        ->and($profile->deleted_at)->not->toBeNull();
});

it('deletes a SECOND performer without colliding on the unique stage_name', function () {
    // Regressão: '[removido]' literal batia no índice único (que cobre linhas
    // soft-deleted) e só a primeira performer do sistema conseguia sair — a
    // segunda fazia rollback silencioso todo dia, com o KYC intacto no disco.
    $first = delPerformer();
    $second = delPerformer();

    delService()->executeDeletion($first);
    delService()->executeDeletion($second);

    expect(User::withTrashed()->find($first->id)->deleted_at)->not->toBeNull()
        ->and(User::withTrashed()->find($second->id)->deleted_at)->not->toBeNull();
});

it('purges PII living outside the users table', function () {
    $user = delMember();
    $email = $user->email;

    DB::table('waitlist_entries')->insert([
        'name' => 'Fulano de Tal',
        'email' => $email,
        'role' => 'member',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('password_reset_tokens')->insert([
        'email' => $email,
        'token' => 'irrelevante',
        'created_at' => now(),
    ]);

    delService()->executeDeletion($user);

    // A waitlist guarda nome e e-mail em claro e não tem FK com users — some de
    // qualquer varredura por user_id. password_reset_tokens tem o e-mail na PK.
    expect(DB::table('waitlist_entries')->where('email', $email)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $email)->count())->toBe(0);
});

it('clears the sexual-orientation preference and other sensitive flags', function () {
    $user = delMember();
    $user->forceFill(['preferred_world' => 'trans', 'discrete_mode' => true])->save();

    delService()->executeDeletion($user);

    $row = DB::table('users')->where('id', $user->id)->first();

    // preferred_world é dado sensível de vida sexual (LGPD art. 5º, II).
    expect($row->preferred_world)->toBeNull()
        ->and((bool) $row->discrete_mode)->toBeFalse();
});

it('blocks deletion while a PIX charge is still open', function () {
    $user = delMember();

    $payment = DB::table('payments')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'asaas',
        'method' => 'pix',
        'status' => 'pending',
        'amount_cents' => 5000,
        'tokens' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Liquidar depois do encerramento creditaria tokens numa wallet sem titular.
    expect(fn () => delService()->executeDeletion($user))->toThrow(RuntimeException::class);

    DB::table('payments')->where('id', $payment)->update(['status' => 'confirmed']);

    delService()->executeDeletion($user->fresh());

    expect(User::withTrashed()->find($user->id)->deleted_at)->not->toBeNull();
});

it('does not let an abandoned PIX charge block deletion forever', function () {
    $user = delMember();

    DB::table('payments')->insert([
        'user_id' => $user->id,
        'provider' => 'asaas',
        'method' => 'pix',
        'status' => 'pending',
        'amount_cents' => 5000,
        'tokens' => 100,
        // Cobrança abandonada que o reconcile nunca expirou: passados 7 dias ela
        // não pode mais segurar o prazo legal do titular.
        'created_at' => now()->subDays(30),
        'updated_at' => now()->subDays(30),
    ]);

    delService()->executeDeletion($user);

    expect(User::withTrashed()->find($user->id)->deleted_at)->not->toBeNull();
});

it('keeps the 18+ proof but destroys the CPF digest', function () {
    $user = delMember();

    DB::table('age_verifications')->insert([
        'user_id' => $user->id,
        'method' => 'cpf',
        'cpf_hmac' => str_repeat('a', 64),
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    delService()->executeDeletion($user);

    $row = DB::table('age_verifications')->where('user_id', $user->id)->first();

    // A prova de que a plataforma checou os 18+ fica; o identificador do CPF sai.
    expect($row)->not->toBeNull()
        ->and($row->verified_at)->not->toBeNull()
        ->and($row->cpf_hmac)->toBeNull();
});

it('preserves document acceptances as the legal ballast', function () {
    $performer = delPerformer();

    DB::table('document_acceptances')->insert([
        'user_id' => $performer->id,
        'document_type' => 'performance_contract',
        'document_version' => '1.0',
        'accepted_at' => now(),
        'ip_address_hash' => str_repeat('b', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A performer já nasce com os aceites do cadastro; o insert acima só
    // acrescenta um. O que importa é que a exclusão não leva NENHUM deles.
    $before = DB::table('document_acceptances')->where('user_id', $performer->id)->count();

    delService()->executeDeletion($performer);

    // Append-only e lastro jurídico do aceite — IP/UA já são HMAC.
    expect(DB::table('document_acceptances')->where('user_id', $performer->id)->count())
        ->toBe($before)
        ->toBeGreaterThan(0);
});

it('clears the registration IP digest that correlates accounts', function () {
    $user = delMember();
    DB::table('users')->where('id', $user->id)
        ->update(['registration_ip_hash' => str_repeat('c', 64)]);

    delService()->executeDeletion($user->fresh());

    expect(DB::table('users')->where('id', $user->id)->value('registration_ip_hash'))->toBeNull();
});

it('emails the holder when a deletion request is cancelled', function () {
    Mail::fake();

    $user = delMember();
    delService()->requestDeletion($user);

    delService()->cancelDeletion($user->fresh());

    // Sessão sequestrada que cancela um pedido legítimo tem que deixar rastro
    // FORA da sessão — senão o titular acha que está saindo e não está.
    Mail::assertQueued(AccountDeletionCancelledMail::class);
});

it('records a data_summary of counts without any PII', function () {
    $performer = delPerformer();
    delPayout($performer, 'paid');

    $log = delService()->executeDeletion($performer);

    expect($log->executed_at)->not->toBeNull()
        ->and($log->data_summary)->toHaveKey('preserved')
        ->and($log->data_summary['preserved']['payouts'])->toBe(1);

    // Nenhum valor do resumo pode ser texto livre — só contagens e booleanos.
    $json = json_encode($log->data_summary);
    expect($json)->not->toContain('@')
        ->and($json)->not->toContain($performer->name);
});

// ---------------------------------------------------------------------------
// Payout em aberto trava a exclusão
// ---------------------------------------------------------------------------

it('blocks deletion while a payout is still in flight', function () {
    foreach (['pending', 'processing', 'needs_review'] as $status) {
        $performer = delPerformer();
        delPayout($performer, $status);

        expect(fn () => delService()->executeDeletion($performer))
            ->toThrow(RuntimeException::class);

        expect(User::withTrashed()->find($performer->id)->deleted_at)->toBeNull();
    }
});

it('allows deletion once the payout settles', function () {
    $performer = delPerformer();
    $payout = delPayout($performer, 'pending');

    expect(fn () => delService()->executeDeletion($performer))
        ->toThrow(RuntimeException::class);

    $payout->update(['status' => 'paid']);

    delService()->executeDeletion($performer->fresh());

    expect(User::withTrashed()->find($performer->id)->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Job diário
// ---------------------------------------------------------------------------

it('processes only users whose grace period has expired', function () {
    Mail::fake();

    $due = delMember();
    $notDue = delMember();
    $untouched = delMember();

    delService()->requestDeletion($due);
    delService()->requestDeletion($notDue);

    // 29 dias: o due (pedido hoje) ainda não venceu...
    $this->travel(31)->days();

    // ...então mexemos só no notDue para empurrá-lo para o futuro.
    $notDue->forceFill(['deletion_scheduled_at' => now()->addDays(10)])->save();

    $this->artisan('deletions:process')->assertSuccessful();

    expect(User::withTrashed()->find($due->id)->deleted_at)->not->toBeNull()
        ->and(User::find($notDue->id)->deleted_at)->toBeNull()
        ->and(User::find($untouched->id)->deleted_at)->toBeNull();
});

it('keeps sweeping the batch when one user is blocked by a payout', function () {
    Mail::fake();

    $blocked = delPerformer();
    $deletable = delMember();

    delService()->requestDeletion($blocked);
    delService()->requestDeletion($deletable);

    // O saque entra na fila DEPOIS do pedido aceito — é justamente por isso que
    // assertDeletable roda de novo na execução, e não só no pedido.
    delPayout($blocked, 'pending');

    $this->travel(31)->days();

    $this->artisan('deletions:process')->assertSuccessful();

    expect(User::withTrashed()->find($deletable->id)->deleted_at)->not->toBeNull()
        ->and(User::find($blocked->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Token de confirmação
// ---------------------------------------------------------------------------

it('expires the confirmation token after 48 hours', function () {
    Mail::fake();

    $user = delMember();
    $token = delService()->requestDeletion($user);

    expect(delService()->userForToken($token)?->id)->toBe($user->id);

    $this->travel(49)->hours();

    expect(delService()->userForToken($token))->toBeNull();
});

it('stores the confirmation token hashed, never in the clear', function () {
    Mail::fake();

    $user = delMember();
    $token = delService()->requestDeletion($user);

    expect($user->fresh()->deletion_token_hash)
        ->not->toBe($token)
        ->toBe(hash('sha256', $token));
});

it('burns the confirmation token on use', function () {
    Mail::fake();

    $user = delMember();
    $token = delService()->requestDeletion($user);

    delService()->confirmDeletion(delService()->userForToken($token));

    expect($user->fresh()->deletion_confirmed_at)->not->toBeNull()
        ->and(delService()->userForToken($token))->toBeNull();
});

// ---------------------------------------------------------------------------
// Rotas web
// ---------------------------------------------------------------------------

it('lets a member request and cancel deletion from the web', function () {
    Mail::fake();

    $user = delMember();

    $this->actingAs($user)
        ->post(route('account.deletion.request'))
        ->assertRedirect();

    expect($user->fresh()->deletion_scheduled_at)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('account.deletion.cancel'))
        ->assertRedirect();

    expect($user->fresh()->deletion_scheduled_at)->toBeNull();
});

it('lets a performer request deletion too', function () {
    Mail::fake();

    $performer = delPerformer();

    $this->actingAs($performer)
        ->post(route('account.deletion.request'))
        ->assertRedirect();

    expect($performer->fresh()->deletion_scheduled_at)->not->toBeNull();
});

it('refuses the web request when a payout is in flight', function () {
    Mail::fake();

    $performer = delPerformer();
    delPayout($performer, 'pending');

    $this->actingAs($performer)
        ->postJson(route('account.deletion.request'))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'payout_pending');

    expect($performer->fresh()->deletion_scheduled_at)->toBeNull();
    Mail::assertNothingQueued();
});

it('does not consume the token on GET, so mailbox prefetch cannot confirm', function () {
    Mail::fake();

    $user = delMember();
    $token = delService()->requestDeletion($user);

    $this->get(route('account.deletion.confirm', ['token' => $token]))->assertOk();

    // O GET não confirmou nem queimou o token — o POST é que age.
    expect($user->fresh()->deletion_confirmed_at)->toBeNull()
        ->and(delService()->userForToken($token))->not->toBeNull();

    $this->post(route('account.deletion.confirm.store'), ['token' => $token])
        ->assertRedirect();

    expect($user->fresh()->deletion_confirmed_at)->not->toBeNull();
});

it('rejects an unknown confirmation token', function () {
    $this->post(route('account.deletion.confirm.store'), ['token' => Str::random(64)])
        ->assertRedirect();

    expect(session('error'))->not->toBeNull();
});

it('requires authentication to request deletion', function () {
    $this->post(route('account.deletion.request'))->assertRedirect(route('login'));
});

it('does not accept deletion columns via mass assignment', function () {
    $user = delMember();

    $user->fill([
        'deletion_requested_at' => now(),
        'deletion_scheduled_at' => now(),
    ]);
    $user->save();

    expect($user->fresh()->deletion_scheduled_at)->toBeNull();
});
