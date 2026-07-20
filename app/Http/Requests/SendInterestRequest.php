<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\FollowerVisibilityService;
use App\Support\FanAlias;
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
            // Handle opaco (App\Support\FanAlias), não o id: o id do membro nunca
            // chega ao front da performer, então também não volta dele.
            'member_handle' => ['required', 'string'],
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
        $visibility = app(FollowerVisibilityService::class);

        // A premissa acima ("ela já conhece os seguidores") deixou de valer com o
        // Piso de Anonimato: abaixo do piso ela NÃO conhece, e o par 404/201
        // reconstruiria a lista que a tela esconde. O handle opaco encarece muito
        // essa varredura (64 bits não se adivinham), mas o piso continua sendo a
        // regra — não trocamos uma barreira de autorização por obscuridade.
        abort_unless($profile && $visibility->canRevealList($profile->id), 404);

        // Resolvido contra o MESMO predicado que monta a tela: só quem a lista
        // mostraria tem handle resolvível. Handle de membro discreto, inativo ou
        // que não segue este perfil é indistinguível de handle inventado.
        //
        // Sem índice reverso: o handle é HMAC, então refazemos o de cada
        // candidato. É uma varredura dos seguidores listáveis deste perfil por
        // envio — barato na escala real, e o limite diário de interesses já
        // limita a frequência.
        $memberId = FanAlias::resolveHandle(
            $profile->id,
            $visibility->listableQuery($profile->id)->pluck('user_id'),
            (string) $this->validated('member_handle'),
        );

        // Redundante com a resolução acima (mesmo predicado), de propósito: é o
        // método que carrega o nome da invariante, e mantê-lo no caminho impede
        // que uma mudança futura na resolução afrouxe a regra em silêncio.
        abort_unless($memberId && $visibility->canReceiveInterest($profile, $memberId), 404);

        return User::where('id', $memberId)
            ->where('role', 'consumer')
            ->where('status', 'active')
            ->firstOrFail();
    }
}
