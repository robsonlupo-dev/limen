<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\ImageProcessingException;
use App\Exceptions\MemberPhotoException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ShareMemberPhotoRequest;
use App\Http\Requests\Web\StoreMemberPhotoRequest;
use App\Models\MemberPhoto;
use App\Models\PerformerProfile;
use App\Services\MemberPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Fotos efêmeras do MEMBRO: envio, compartilhamento, revogação e leitura.
 * Ver docs/SECURITY_ISSUES.md § 1.1 a § 1.11.
 *
 * O controller só delega — cap, prazo, propriedade e o gate de chat ativo vivem
 * no `MemberPhotoService` (item 9 do CLAUDE.md). O que é responsabilidade DAQUI
 * é a tradução para HTTP, e ela não é automática: o front fala com as rotas WEB,
 * onde uma exceção **não** vira JSON sozinha (só em `api/*`). Sem o
 * `response()->json()` explícito, o Vue receberia uma página de erro HTML e o
 * `postJson` estouraria no `response.json()`.
 */
class MemberPhotoController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(private MemberPhotoService $photos) {}

    /**
     * Recebe a foto. O cap de 5 ativas é conferido sob lock lá dentro (§ 1.6).
     */
    public function store(StoreMemberPhotoRequest $request): JsonResponse
    {
        try {
            $photo = $this->photos->create(
                $request->user(),
                $request->file('foto'),
                (int) $request->integer('ttl_horas'),
            );
        } catch (MemberPhotoException|ImageProcessingException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        // A resposta devolve a MESMA faixa que a tela mostra — nunca o
        // `expires_at`, nem o TTL escolhido de volta (§ 1.2).
        return response()->json(['photo' => $photo->presentForMember()], 201);
    }

    /**
     * Compartilha com uma performer. O aviso de des-anonimização (§ 1.1) é
     * mostrado ANTES desta chamada, no modal — aqui ele já é decisão tomada.
     */
    public function share(ShareMemberPhotoRequest $request, MemberPhoto $photo): JsonResponse
    {
        // `find()` e não `findOrFail()`: a exceção do segundo vira 404 **HTML**
        // numa rota web (o `shouldRenderJsonWhen` só cobre `api/*`), e o
        // `postJson` do front faz `response.json()` — o membro veria o erro
        // genérico do catch. É o mesmo buraco que o FailsValidationAsJson fecha
        // para validação, num caminho que não passa por validação.
        //
        // A resposta é a MESMA de "sem chat ativo", e isso não é preguiça: o
        // `exists:` do Form Request já passou (ele consulta a tabela e não
        // aplica o global scope de soft delete), então chegar aqui com `null`
        // significa perfil ENCERRADO. Um erro próprio para esse caso diria ao
        // membro que a conta dela foi encerrada — estado da conta dela, não
        // dele. Quem decide o resto do estado da performer é o Service.
        $profile = PerformerProfile::find($request->integer('performer_profile_id'));

        if ($profile === null) {
            $notReachable = MemberPhotoException::noActiveChat();

            return response()->json([
                'reason' => $notReachable->reason,
                'message' => $notReachable->getMessage(),
            ], 422);
        }

        try {
            $this->photos->shareWith($request->user(), $photo, $profile);
        } catch (MemberPhotoException $e) {
            // 403 para "não é sua foto"; 422 para as recusas de estado (sem chat
            // ativo, foto vencida) — são erros que a tela sabe explicar.
            $status = $e->reason === MemberPhotoException::FORBIDDEN ? 403 : 422;

            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $status);
        }

        return response()->json(['photo' => $photo->fresh()->presentForMember()]);
    }

    /**
     * Revoga: bytes do disco, acessos do banco, linha de vez.
     *
     * 403 para "não é sua foto" e 422 para "está em análise" — a segunda é
     * recusa de ESTADO, não de autorização, e a tela precisa distinguir para
     * explicar. É o mesmo par de status que `share()` já usa.
     */
    public function destroy(Request $request, MemberPhoto $photo): JsonResponse
    {
        try {
            $this->photos->destroyForMember($request->user(), $photo);
        } catch (MemberPhotoException $e) {
            $status = $e->reason === MemberPhotoException::FORBIDDEN ? 403 : 422;

            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $status);
        }

        return response()->json(['status' => 'revoked']);
    }

    /**
     * Serve a foto ao próprio titular.
     *
     * 404 tanto para "não é sua" quanto para "venceu", e isso é deliberado: são
     * a mesma resposta porque distingui-las diria a um terceiro que aquele id
     * existe e está vivo. O `MemberPhotoException` já sai com a mensagem
     * uniforme; aqui o status acompanha.
     */
    public function image(Request $request, MemberPhoto $photo): Response
    {
        try {
            $bytes = $this->photos->readForMember($request->user(), $photo);
        } catch (MemberPhotoException) {
            abort(404);
        }

        return $this->photoResponse($bytes);
    }
}
