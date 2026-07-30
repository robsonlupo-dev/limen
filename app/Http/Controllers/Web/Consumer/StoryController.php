<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\StoryException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Models\PerformerStory;
use App\Services\PerformerStoryService;
use App\Services\StoryVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stories do lado do MEMBRO: o feed e os bytes da imagem.
 * Ver docs/SECURITY_ISSUES.md § 2.3 e § 2.8.
 *
 * ── Nada aqui decide quem vê o quê ──────────────────────────────────────────
 * O paywall é do `StoryVisibilityService` e a leitura entra pelo
 * `PerformerStoryService::readForMember()`. Isso não é cerimônia: o § 2.3 é
 * exatamente sobre o que acontece quando a autorização mora no transporte — o
 * `performer.media` assinado de `routes/api.php` não amarra viewer nenhum, e a URL
 * do membro Black vira um bearer token colável no WhatsApp. Aqui a autorização é
 * de SESSÃO e é reavaliada em cada request; follow e tier são resolvidos na hora.
 *
 * **Não existe rota assinada de story, e não é para existir.** Se algum dia
 * entrar, é adicional e curtíssima — nunca substituta da checagem.
 */
class StoryController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(
        private PerformerStoryService $stories,
        private StoryVisibilityService $visibility,
    ) {}

    /**
     * Feed do membro, agrupado por performer, com o indicador de não-visto.
     *
     * JSON: é consumido pelo componente do catálogo (PR 3) e pelo painel; o
     * agrupamento e o filtro por nível vêm do service, que aplica o MESMO
     * `canView()` do serving — se as duas superfícies discordassem, o par
     * (aparece no feed / 403 na imagem) viraria oráculo do tier alheio, e o
     * contrário seria furo de paywall.
     */
    public function feed(Request $request): JsonResponse
    {
        return response()->json([
            'performers' => $this->visibility->feedFor($request->user()),
        ]);
    }

    /**
     * Serve a imagem do story.
     *
     * 403 quando o nível não alcança e 404 quando venceu (ou nunca existiu), e a
     * distinção é decisão de produto: o Modelo C monetiza dizendo que existe
     * conteúdo que o membro ainda não pode ver — é o "cria incentivo para assinar
     * Círculo" do § 2.3. O que a resposta NÃO revela continua fechado: nada de
     * quem viu, quantos viram, ou o que o feed dele não lista.
     *
     * A view é registrada dentro do Service, depois de os bytes saírem do disco, e
     * com os guards de Ghost Mode/Modo Discreto (§ 2.7). A resposta é IDÊNTICA
     * tenha a view sido gravada ou não — se diferisse, o perk seria detectável de
     * fora, que é o mesmo cuidado do `ProfileVisitService::record()`.
     */
    public function image(Request $request, PerformerStory $story): Response
    {
        try {
            $bytes = $this->stories->readForMember($request->user(), $story);
        } catch (StoryException $e) {
            abort($e->reason === StoryException::FORBIDDEN ? 403 : 404);
        }

        return $this->photoResponse($bytes, 'story.jpg');
    }
}
