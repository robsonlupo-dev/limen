<?php

namespace App\Services;

use App\Exceptions\AccountBlockedException;
use App\Models\AgeVerification;
use App\Models\FraudBlacklist;
use App\Models\IdentityVerification;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\ClientFingerprint;
use App\Support\CpfHash;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function registerConsumer(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'birthdate' => $data['birthdate'],
                'lgpd_consent_at' => now(),
                'terms_version' => $data['terms_version'],
            ]);
            $user->role = 'consumer';
            $user->status = 'active';
            // preferred_world is intentionally kept out of $fillable and set
            // explicitly here to avoid mass-assignment of privileged fields.
            $user->preferred_world = $data['preferred_world'] ?? null;

            // Lista negra antifraude: se o CPF já esteve associado a uma conta
            // banida, marca a conta nova (SINAL, não bloqueio — mesma disciplina
            // do shared-IP flag; a fila humana decide). O hash morre aqui, como
            // no age_verification abaixo. `blacklist_hit` fica FORA do $fillable:
            // atribuição explícita, nunca payload.
            $cpfHash = isset($data['cpf']) ? CpfHash::make($data['cpf']) : null;
            $user->blacklist_hit = $cpfHash !== null && FraudBlacklist::hasCpfHash($cpfHash);
            $user->save();

            // NOTA: `users.age_verified_at` NÃO é preenchido aqui, embora seja
            // tentador. Aquela coluna hoje só é marcada pelo KycService, quando
            // um documento passou por provedor (Didit). Marcá-la também para
            // "CPF válido + data declarada" faria um `whereNotNull` misturar os
            // dois níveis e tratar declaração como documento conferido. O sinal
            // do membro fica em `age_verifications`, onde `method` diz o que foi
            // de fato verificado.
            AgeVerification::create([
                'user_id' => $user->id,
                'method' => AgeVerification::METHOD_CPF_DOB,
                // O CPF morre aqui: entra como argumento, sai como digest (o
                // mesmo já computado acima para a checagem da lista negra).
                'cpf_hmac' => $cpfHash,
                'verified_at' => now(),
            ]);

            TokenWallet::create(['user_id' => $user->id, 'balance' => 0]);

            event(new Registered($user));

            return $user;
        });
    }

    /**
     * @param  ?Request  $request  origem HTTP do cadastro, para registrar o IP
     *                             (como HMAC) e detectar rede de exploração.
     *                             Null em seeder/console: ali não há IP real, e
     *                             gravar o 127.0.0.1 do console faria a massa
     *                             sintética inteira nascer sinalizada.
     */
    public function registerPerformer(array $data, ?Request $request = null): User
    {
        return DB::transaction(function () use ($data, $request) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'birthdate' => $data['birthdate'],
                'lgpd_consent_at' => now(),
                'terms_version' => $data['terms_version'],
            ]);
            $user->role = 'performer';
            $user->status = 'pending';
            // Atribuição direta, FORA do $fillable, pelo mesmo motivo de `role`
            // e `discrete_mode`: se fosse preenchível, quem se cadastra mandaria
            // o próprio `registration_ip_hash` no payload e escolheria com quem
            // colidir — ou com ninguém, escapando do flag que existe para
            // protegê-la.
            $user->registration_ip_hash = ClientFingerprint::hash($request?->ip());
            $user->save();

            $user->performerProfile()->create([
                'stage_name' => $data['stage_name'],
                'category' => $data['category'] ?? 'mulheres',
                // Multi-worlds source of truth. Null when the caller sent only
                // `category` (legacy path) — activeWorlds() falls back to it.
                'worlds' => ! empty($data['worlds'])
                    ? array_values(array_unique($data['worlds']))
                    : null,
            ]);

            IdentityVerification::create([
                'user_id' => $user->id,
                'document_type' => 'cpf',
                'status' => 'pending',
            ]);

            TokenWallet::create(['user_id' => $user->id, 'balance' => 0]);

            event(new Registered($user));

            return $user;
        });
    }

    public function attemptLogin(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        // Credenciais OK, mas moderação barra. Distinto do `null` acima (senha
        // errada): a exceção carrega o status para a porta escolher a mensagem —
        // e por só ser lançada DEPOIS do Hash::check, não vaza status para quem
        // não tem a senha. O bloqueio continua vivendo aqui, no service, não no
        // controller: nenhuma porta de auth loga sem passar por este ponto.
        if ($user->status === 'suspended' || $user->status === 'banned') {
            throw new AccountBlockedException($user->status);
        }

        return $user;
    }
}
