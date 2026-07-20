<?php

namespace App\Http\Requests;

use App\Models\Follow;
use App\Models\User;
use App\Services\FollowerVisibilityService;
use Illuminate\Foundation\Http\FormRequest;

class SendInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer'],
        ];
    }

    /**
     * O alvo precisa ser um membro (consumer) ativo QUE JÁ SEGUE esta performer
     * — a lista de seguidores é a única origem de envio (ver FollowersController).
     *
     * Resolver pelo follow é também o que fecha o oráculo de enumeração: sem
     * ele, um id inexistente dava 404 e um consumer ativo dava 422 (cooldown /
     * daily_limit), então a performer varria o espaço de ids e aprendia quem é
     * membro da plataforma. Restrito aos seguidores, todo id que não a segue é
     * indistinguível de um id que não existe — e ela já conhece os seguidores.
     *
     * Não revelar o opt-out aqui: o serviço trata o opt-out silenciosamente.
     */
    public function resolvedMember(): User
    {
        $profile = $this->user()->performerProfile;

        // A premissa acima ("ela já conhece os seguidores") deixou de valer com o
        // Piso de Anonimato: abaixo do piso ela NÃO conhece, então bastaria varrer
        // ids e ler o par 404/201 para reconstruir a lista que a tela esconde. E
        // um membro em Modo Discreto nunca pode receber interesse. Por isso o
        // alvo é resolvido pelo mesmo predicado que monta a tela — um id fora da
        // lista visível é indistinguível de um id que não existe.
        abort_unless(
            $profile && app(FollowerVisibilityService::class)
                ->canReceiveInterest($profile, (int) $this->validated('member_id')),
            404,
        );

        return User::where('id', $this->validated('member_id'))
            ->where('role', 'consumer')
            ->where('status', 'active')
            ->whereIn('id', Follow::where('performer_profile_id', $profile->id)->select('user_id'))
            ->firstOrFail();
    }
}
