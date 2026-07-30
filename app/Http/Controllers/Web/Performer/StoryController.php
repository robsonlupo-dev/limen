<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\ImageProcessingException;
use App\Exceptions\StoryException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreStoryRequest;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Services\PerformerStoryService;
use App\Support\StoryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stories do lado da PERFORMER: publicar, listar os próprios, apagar e ver o
 * thumbnail. Ver docs/SECURITY_ISSUES.md § 2.1 a § 2.10.
 *
 * O controller só delega — prazo, nível, propriedade, contador em faixa e o
 * guard de audiência vivem no `PerformerStoryService` e no
 * `StoryVisibilityService` (item 9 do CLAUDE.md). O que é responsabilidade DAQUI
 * é a tradução para HTTP, e ela não é automática: o front fala com as rotas WEB,
 * onde uma exceção **não** vira JSON sozinha (só em `api/*`). Sem o
 * `response()->json()` explícito o Vue receberia HTML e o `postForm` estouraria
 * no `response.json()`.
 */
class StoryController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(private PerformerStoryService $stories) {}

    /**
     * Os stories vivos dela, com o contador em FAIXA.
     *
     * JSON e não uma página Inertia própria: a seção "Meus Stories" vive no
     * painel (`Performer\DashboardController` monta as props iniciais), e este
     * endpoint é o que o componente chama para recarregar depois de publicar ou
     * apagar. Uma segunda página renderizaria a mesma lista por outro caminho —
     * e é o caminho duplicado que costuma nascer sem a faixa.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'stories' => StoryPresenter::forOwner($this->profile($request), $this->stories),
        ]);
    }

    /**
     * Publica. Prazo fixo de 24h e strip de EXIF/GPS acontecem no Service.
     *
     * `ImageProcessingException` em 422 com o motivo: imagem-bomba, formato
     * recusado e arquivo corrompido são erros que a tela sabe explicar, e o teto
     * de dimensões é lido do HEADER antes de qualquer decodificação.
     *
     * `InvalidArgumentException` (nível fora dos três) NÃO é tratada aqui de
     * propósito: o Form Request já recusou, então chegar lá é erro de programação
     * e tem de estourar 500 em teste/staging, não virar 422 silencioso.
     */
    public function store(StoreStoryRequest $request): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $story = $this->stories->publish(
                $profile,
                $request->file('imagem'),
                (string) $request->string('visibility_level'),
            );
        } catch (ImageProcessingException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'story' => StoryPresenter::one($story, $this->stories),
        ], 201);
    }

    /**
     * Apaga: bytes do disco, views e linha (soft delete).
     *
     * Entra por `destroyForOwner()` e não pelo primitivo `destroy()`: com
     * route-model binding e um controller que só delega, o primitivo sem ator
     * deixaria qualquer performer autenticada apagar o story de outra.
     *
     * 403 uniforme para "não é seu" — a mensagem do `StoryException::notOwner()`
     * é a mesma de "não está disponível", então a resposta não confirma que aquele
     * id existe e é de outra pessoa.
     */
    public function destroy(Request $request, PerformerStory $story): JsonResponse
    {
        try {
            $this->stories->destroyForOwner($this->profile($request), $story);
        } catch (StoryException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 403);
        }

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Thumbnail para o painel dela.
     *
     * Rota separada da do membro de propósito (ver `readForOwner()`): o serving do
     * membro é onde vive o paywall, e um ramo "…ou é a dona" ali seria uma exceção
     * dentro justamente do caminho que precisa ser lido sem ressalva.
     *
     * 403 para story de outra, 404 para vencido — o vencido é indistinguível de
     * inexistente, senão o par de respostas enumeraria o que outra performer
     * publicou e quando.
     */
    public function image(Request $request, PerformerStory $story): Response
    {
        try {
            $bytes = $this->stories->readForOwner($this->profile($request), $story);
        } catch (StoryException $e) {
            abort($e->reason === StoryException::FORBIDDEN ? 403 : 404);
        }

        return $this->photoResponse($bytes, 'story.jpg');
    }

    /**
     * O perfil da performer autenticada.
     *
     * 403 e não `firstOrFail`: conta de performer sem perfil é onboarding
     * incompleto, não recurso inexistente — e a rota já exige
     * `can('performer-active')`, então este caminho é defesa contra estado
     * inconsistente, não fluxo de usuário.
     */
    private function profile(Request $request): PerformerProfile
    {
        $profile = $request->user()->performerProfile;

        abort_if($profile === null, 403);

        return $profile;
    }
}
