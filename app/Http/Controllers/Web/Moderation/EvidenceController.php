<?php

namespace App\Http\Controllers\Web\Moderation;

use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Models\MemberPhoto;
use App\Models\Message;
use App\Models\PerformerStory;
use App\Models\Report;
use App\Services\MemberPhotoStore;
use App\Services\PerformerStoryStore;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

/**
 * Visualizador da PROVA RETIDA na fila de moderação (Sprint 13 — item de backlog
 * que o refactor de roles destravou).
 *
 * Até aqui a fila `/moderacao/*` mostrava "member_photo #id" como TEXTO: a
 * evidência congelada por uma denúncia só era alcançável no disco, por SSH. Estes
 * endpoints dão ao moderador acesso VISUAL ao conteúdo denunciado — a foto
 * efêmera, o story, o corpo da mensagem — sem tirar o arquivo do servidor.
 *
 * ── Por que isto é a EXCEÇÃO deliberada ao "retida não é legível por ninguém" ──
 * A foto/story sob denúncia congela os bytes justamente para a revisão humana
 * (2º bloqueador da Foto Efêmera, § 2.4 do story). O CLAUDE.md diz que a foto
 * retida "não é legível por ninguém — nem pela performer que denunciou": isso
 * fecha o canal do PRODUTO (revoke não vira forma de esticar acesso). O moderador
 * é o destinatário para quem a prova foi congelada — é este endpoint, e só ele,
 * que a lê, sob `moderator.access`, com trilha de auditoria de quem viu.
 *
 * ── Privacidade ────────────────────────────────────────────────────────────
 * - **Só serve conteúdo COM denúncia associada.** `report_id + tipo têm que
 *   bater`: sem uma linha de `reports` apontando para exatamente este alvo, é 404
 *   — indistinguível de conteúdo que nunca existiu. Um id de foto/story/mensagem
 *   adivinhado não vira janela para conteúdo não denunciado.
 * - **A foto efêmera expõe o ROSTO do membro — é PII sensível.** Servi-la ao
 *   moderador é des-anonimização (§ 1.1 da Foto Efêmera: o rosto é uma chave de
 *   join global que o TTL não protege). O acesso é necessário para moderar
 *   conteúdo potencialmente ilegal, e o preço é registrado: cada abertura deixa
 *   `moderation.evidence_viewed` no audit — quem viu, qual denúncia, quando. O
 *   conteúdo nunca entra no audit; só a referência à denúncia.
 * - **Sem download.** `inline`, `no-store`, `nosniff` (via ServesPhotoBytes) —
 *   os mesmos cabeçalhos do serving do membro/performer. Não impede print (nada
 *   impede), mas não oferece o botão de baixar.
 * - **Bytes cifrados decifram em memória e vão direto para a resposta** — nunca
 *   para `/tmp` nem para disco em claro (o MemberPhotoStore::retrieve() devolve os
 *   bytes decifrados sem materializar arquivo).
 *
 * O route-model binding é MANUAL (por id numérico + `withTrashed`) de propósito:
 * a prova retida costuma estar SOFT-DELETADA (a foto revogada sob denúncia, a
 * mensagem cuja carência venceu), e o binding implícito do Laravel a esconderia
 * com um 404 — exatamente o conteúdo que esta tela existe para mostrar.
 */
class EvidenceController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(
        private MemberPhotoStore $photoStore,
        private PerformerStoryStore $storyStore,
    ) {}

    /**
     * Serve a foto efêmera denunciada, inline.
     *
     * `withTrashed` porque a foto retida sob denúncia é soft-deletada (o revoke do
     * titular a tira do ar mas preserva os bytes — § 2.4 parte 2 da Foto Efêmera).
     * Bytes ausentes (denúncia já concluída e GC recolheu, ou encerramento de
     * conta que levou os bytes deixando só a linha) devolvem 404: a tela de
     * detalhe já sabe que o conteúdo expirou e mostra o hash no lugar.
     */
    public function photo(int $photo): Response
    {
        $model = MemberPhoto::withTrashed()->find($photo);
        $report = $this->assertReported($model, 'member_photo');

        try {
            $bytes = $this->photoStore->retrieve($model->path_encrypted);
        } catch (Throwable) {
            abort(404);
        }

        $this->auditViewed($report, 'member_photo');

        return $this->photoResponse($bytes, 'evidencia.jpg');
    }

    /**
     * Serve o story denunciado, inline.
     *
     * O story sob denúncia NÃO é soft-deletado (a deleção manual é RECUSADA
     * enquanto a denúncia está aberta — § 2.4 do story), mas pode estar vencido e
     * fora do escopo padrão de leitura; `withTrashed` cobre os dois casos sem que
     * o viewer precise saber qual deles é.
     */
    public function story(int $story): Response
    {
        $model = PerformerStory::withTrashed()->find($story);
        $report = $this->assertReported($model, 'performer_story');

        try {
            $bytes = $this->storyStore->retrieve($model->media_path);
        } catch (Throwable) {
            abort(404);
        }

        $this->auditViewed($report, 'performer_story');

        return $this->photoResponse($bytes, 'evidencia.jpg');
    }

    /**
     * Devolve o CORPO da mensagem denunciada como JSON.
     *
     * A mensagem é o objeto mais sensível do produto: é servida por um endpoint
     * dedicado (revelada por ação do moderador na tela), não embutida na prop da
     * página, para que a abertura seja um evento auditável — como a `<img>` das
     * imagens dispara o GET que registra o `evidence_viewed`.
     *
     * `withTrashed` porque a mensagem é soft-deletada ao vencer a carência do
     * acesso (retida para trilha de abuso/legal), e é justamente essa mensagem
     * retida que a denúncia aponta.
     */
    public function message(int $message): JsonResponse
    {
        $model = Message::withTrashed()->find($message);
        $report = $this->assertReported($model, 'message');

        $this->auditViewed($report, 'message');

        return response()->json([
            'body' => $model->body,
            'sent_at' => $model->created_at,
        ]);
    }

    /**
     * O alvo existe E tem uma denúncia apontando exatamente para ele?
     *
     * É a porta que fecha o oráculo: sem uma linha de `reports` para este par
     * (tipo, id), a resposta é 404 — a mesma de um id que nunca existiu. Serve
     * conteúdo SÓ porque ele foi denunciado, nunca porque o id foi adivinhado.
     *
     * ── E a denúncia tem que estar EM ABERTO ────────────────────────────────
     * A retenção dos bytes (§ 2.4) existe PARA a revisão humana e vale só
     * enquanto a denúncia está aberta (`Report::OPEN_STATUSES`): concluída, o GC
     * volta a recolher os bytes. Servir o ROSTO do membro (PII, des-anonimização)
     * a partir de uma denúncia já encerrada esticaria a janela de exposição além
     * do que a revisão precisa. Fila fechada, o serving falha FECHADO (404) — a
     * mesma resposta de "não existe". Alinhado com a disponibilidade da tela
     * (`ModerationController::evidenceFor`), para as duas não divergirem.
     *
     * Devolve a denúncia aberta mais recente para o audit referenciar — o
     * `evidence_viewed` aponta para a DENÚNCIA, nunca para o conteúdo, e assim não
     * reintroduz o mapa que o Hard Delete apaga.
     *
     * @phpstan-assert !null $model
     */
    private function assertReported(?Model $model, string $alias): Report
    {
        abort_if($model === null, 404);

        $report = Report::query()
            ->where('reportable_type', $model->getMorphClass())
            ->where('reportable_id', $model->getKey())
            ->whereIn('status', Report::OPEN_STATUSES)
            ->latest('id')
            ->first();

        abort_if($report === null, 404);

        return $report;
    }

    /**
     * Trilha da abertura da evidência. O ATOR (o moderador) já entra em `user_id`
     * pelo request; o sujeito é a DENÚNCIA (referência, não conteúdo), e o tipo
     * vai no metadata. Sem o corpo da mensagem, sem bytes, sem caminho no disco —
     * mesma disciplina de `moderation.report_reviewed`.
     */
    private function auditViewed(Report $report, string $alias): void
    {
        Audit::log('moderation.evidence_viewed', $report, ['evidence_type' => $alias]);
    }
}
