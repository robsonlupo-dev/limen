<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Cria uma conta de moderador (Sprint 13 — refactor de roles).
 *
 * O moderador é a conta de EQUIPE que revisa a fila `/moderacao/*` sem os
 * poderes de admin. Não é performer (não tem perfil, não faz KYC) nem consumer.
 * `role` e `status` são autoridade do servidor — ficam fora do $fillable, então
 * a criação passa por aqui (forceFill), nunca por um cadastro público.
 *
 * A senha é gerada aleatória e mostrada UMA vez: o operador a repassa pelo canal
 * seguro, e o moderador pode trocá-la (ou usar o login por código OTP, que já
 * cobre qualquer e-mail verificado). Não fica registrada em lugar nenhum.
 */
class CreateModerator extends Command
{
    protected $signature = 'limen:create-moderator {email : E-mail de login} {name : Nome de exibição}';

    protected $description = 'Cria uma conta de moderador (role=moderator) para a fila de denúncias';

    public function handle(): int
    {
        // Normaliza o e-mail como o OtpService (mb_strtolower(trim)): a conta
        // nasce com a mesma forma que o login vai procurar.
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));

        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = Str::password(20);

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            // Cast `hashed` no model cifra ao setar, mesmo via forceFill.
            'password' => $password,
            'role' => 'moderator',
            'status' => 'active',
            'email_verified_at' => now(),
        ])->save();

        // Audit da criação. Só o fato + o id do novo usuário (subject) — sem a
        // senha, óbvio, e sem e-mail no metadata (o subject_id já aponta a linha).
        Audit::log('moderation.moderator_created', $user, ['role' => 'moderator']);

        $this->info("Moderador criado: {$email} (id {$user->id}).");
        $this->line('Senha temporária (mostrada só agora):');
        $this->line("  {$password}");
        $this->line('Repasse pelo canal seguro. O moderador pode trocar a senha ou usar login por código (OTP).');

        return self::SUCCESS;
    }
}
