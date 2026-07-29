<?php

namespace App\Models;

use App\Support\ExpirySlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acesso de UMA performer a UMA foto efêmera. Ver docs/SECURITY_ISSUES.md § 1.8.
 *
 * Sem soft delete, de propósito: a linha morre junto com a foto, em DELETE de
 * verdade (`member-photos:purge`). Guardar o acesso "apagado" manteria o mapa de
 * quem mostrou o rosto para quem a um JOIN de distância — o mesmo raciocínio que
 * mantém `profile_visits` com retenção curta e sem coluna `hidden`.
 */
class MemberPhotoAccess extends Model
{
    protected $table = 'member_photo_access';

    /**
     * Faixas de tempo restante. Os valores vivem em `App\Support\ExpirySlot` —
     * a lista de fotos do próprio membro exibe a MESMA faixa, e duas cópias
     * divergiriam. Estes apelidos ficam porque são o vocabulário pelo qual as
     * telas e os testes já se referem à faixa.
     */
    public const SLOT_EXPIRED = ExpirySlot::EXPIRED;

    public const SLOT_TODAY = ExpirySlot::TODAY;

    public const SLOT_SOME_DAYS = ExpirySlot::SOME_DAYS;

    public const SLOT_THIS_WEEK = ExpirySlot::THIS_WEEK;

    /**
     * Quase tudo fica FORA do fillable, na mesma disciplina do `MemberPhoto`
     * (que mantém dono e caminho fora) e de `discrete_mode`:
     *
     * - as duas FKs: quem escolhe a foto e a performer é o Service, a partir do
     *   titular autenticado. Aceitas por payload, um `create($request->validated())`
     *   deixaria o cliente conceder acesso sobre foto alheia;
     * - `expires_at`: é DERIVADO — o menor entre o pedido e o prazo da foto
     *   (MemberPhotoService::grantTo()). Vindo do request, o cliente furaria o
     *   clamp e teria um acesso que sobrevive ao conteúdo, que é a regra § 1.8
     *   inteira;
     * - `viewed_at`: é a ação da performer, marcada por markViewed().
     *
     * Sobra `granted_at`, que é carimbo de relógio do servidor e não decide nada.
     */
    protected $fillable = [
        'granted_at',
    ];

    /**
     * Nunca em serialização — nem para a performer (é a ação dela) nem para o
     * membro (seria relógio: § 1.8). O que o membro pode ver é o booleano de
     * presentForMember().
     */
    protected $hidden = [
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(MemberPhoto::class, 'member_photo_id');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    /** Vencido pelo relógio. Conferido na LEITURA, não pelo job (§ 1.3). */
    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function markViewed(): void
    {
        // Só a PRIMEIRA visualização. Reescrever a cada abertura transformaria a
        // coluna num log de quando a performer volta a olhar a foto — dado que
        // ninguém consome e que nenhuma tela pode mostrar.
        if ($this->viewed_at === null) {
            $this->forceFill(['viewed_at' => now()])->save();
        }
    }

    /**
     * Tempo restante em FAIXA, nunca em relógio (§ 1.2).
     *
     * Um countdown *"expira em 71h48"* devolve `granted_at` ao minuto — e é pior
     * que o oráculo do `visited_at` que o projeto já pagou para fechar (item 12
     * do CLAUDE.md), porque o TTL vem de um menu de três opções e a performer
     * conhece a base da subtração exatamente. A faixa é o mesmo vocabulário de
     * `ProfileVisitService::slot()`: grossa o bastante para não casar com um
     * envio pontual.
     *
     * O fuso vem de `ProfileVisitService::DISPLAY_TIMEZONE` e não de
     * `config('app.timezone')` (que é UTC), pela razão de sempre: "hoje" é uma
     * palavra lida por gente no Brasil. Vem da CONSTANTE, e não de uma cópia
     * local, para não existir uma segunda definição de "hoje" para divergir.
     *
     * > **Ressalva conhecida — a faixa reduz a resolução, não elimina o sinal
     * > do TTL.** Uma foto que nasce em "Expira nesta semana" não escolheu 24h,
     * > e isso é visível já no primeiro carregamento. Qualquer indicador de
     * > tempo restante tem essa propriedade; o que a faixa compra é que o
     * > instante do envio não sai mais junto. Mesma disciplina de linguagem do
     * > painel de visitantes: **não descreva o indicador como "não revela nada
     * > sobre o envio"**. Fechar por completo exigiria não exibir tempo algum.
     */
    public function timeRemainingSlot(): string
    {
        return ExpirySlot::for($this->expires_at);
    }

    /**
     * O que a performer pode ver sobre o acesso — e nada além.
     *
     * Existe para que a resposta seja UMA, montada aqui: a segunda tela que
     * montasse o payload à mão (API, export, um componente Vue novo) nasceria
     * carregando `viewed_at` ou `expires_at` cru nas props do Inertia, que é
     * exatamente o erro que o FanAlias fechou ao tirar o `member_id` do payload.
     *
     * Fora daqui, deliberadamente: `viewed_at` (§ 1.8), `granted_at` e
     * `expires_at` em relógio (§ 1.2) e o TTL escolhido pelo membro.
     *
     * @return array{expires_slot:string}
     */
    public function presentForPerformer(): array
    {
        return [
            'expires_slot' => $this->timeRemainingSlot(),
        ];
    }

    /**
     * O que o titular da foto vê sobre um acesso que ele concedeu.
     *
     * Ele pode saber que a foto foi aberta — é a foto dele —, mas em booleano e
     * não em relógio: a hora exata seria o recibo de leitura da performer,
     * lateral a esta feature e não decidido aqui.
     *
     * @return array{expires_slot:string,viewed:bool}
     */
    public function presentForMember(): array
    {
        return [
            'expires_slot' => $this->timeRemainingSlot(),
            'viewed' => $this->viewed_at !== null,
        ];
    }
}
