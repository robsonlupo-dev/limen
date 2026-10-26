<?php

namespace App\Support;

use App\Models\Referral;
use App\Models\User;
use App\Services\ReferralService;

/**
 * Dados da tela/e-mail de pós-cadastro (feat/signup-email-gate-and-whatsapp-optin),
 * montados num lugar só para a página Inertia (VerifyEmail.vue) e o e-mail de
 * confirmação contarem a MESMA história de bônus. Nada aqui vaza dado sensível: só
 * o papel, o código/link de indicação do próprio usuário, os valores de bônus (que
 * vêm do config) e contadores agregados das indicações DELE.
 */
class SignupWelcomeData
{
    public function __construct(private ReferralService $referrals) {}

    /**
     * @return array{
     *   role: string,
     *   referral_enabled: bool,
     *   referral_code: ?string,
     *   referral_link: ?string,
     *   bonuses: array<string, array{referrer:int, referred:int}>,
     *   counters: array{sent:int, confirmed:int, pending_tokens:int},
     *   email_masked: string,
     *   email_verified: bool,
     *   whatsapp_opted_in: bool,
     *   whatsapp_masked: ?string
     * }
     */
    public function for(User $user): array
    {
        $role = $user->role === 'performer' ? 'performer' : 'member';
        $enabled = $this->referrals->enabled();

        $code = null;
        $link = null;
        if ($enabled) {
            $code = $this->referrals->ensureCode($user);
            $link = rtrim((string) config('app.url'), '/').'/cadastro?ref='.$code;
        }

        return [
            'role' => $role,
            'referral_enabled' => $enabled,
            'referral_code' => $code,
            'referral_link' => $link,
            'bonuses' => [
                'member_purchase' => [
                    'referrer' => (int) config('referral.rewards.member_purchase.referrer', 10),
                    'referred' => (int) config('referral.rewards.member_purchase.referred', 5),
                ],
                'performer_first_earning' => [
                    'referrer' => (int) config('referral.rewards.performer_first_earning.referrer', 40),
                    'referred' => (int) config('referral.rewards.performer_first_earning.referred', 25),
                ],
            ],
            'counters' => $this->counters($user, $enabled),
            'email_masked' => $this->maskEmail($user->email),
            'email_verified' => $user->hasVerifiedEmail(),
            'whatsapp_opted_in' => filled($user->whatsapp_consent_at),
            'whatsapp_masked' => BrazilPhone::mask($user->whatsapp),
        ];
    }

    /**
     * Contadores das indicações feitas por este usuário: convites que viraram
     * cadastro, quantos já confirmaram o e-mail, e os tokens que ele tem a receber
     * (soma das recompensas ainda não creditadas das indicações dele). Agregado —
     * nunca expõe QUEM indicou.
     *
     * @return array{sent:int, confirmed:int, pending_tokens:int}
     */
    private function counters(User $user, bool $enabled): array
    {
        if (! $enabled) {
            return ['sent' => 0, 'confirmed' => 0, 'pending_tokens' => 0];
        }

        $referrals = Referral::where('referrer_user_id', $user->id)
            ->with('referred:id,email_verified_at')
            ->get();

        $sent = $referrals->count();
        $confirmed = $referrals->filter(fn ($r) => filled($r->referred?->email_verified_at))->count();

        $pendingTokens = (int) \App\Models\ReferralReward::where('beneficiary_user_id', $user->id)
            ->whereIn('status', ['pending'])
            ->sum('amount');

        return ['sent' => $sent, 'confirmed' => $confirmed, 'pending_tokens' => $pendingTokens];
    }

    /** ma•••@gmail.com — nunca o e-mail inteiro na tela. */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';

        $head = mb_substr($local, 0, 2);

        return $head.'•••@'.$domain;
    }
}
