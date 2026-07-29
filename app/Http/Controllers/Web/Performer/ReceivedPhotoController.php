<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\MemberPhotoException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Models\MemberPhotoAccess;
use App\Services\MemberPhotoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Leitura, pela PERFORMER, de uma foto que um membro compartilhou com ela.
 *
 * A listagem não está aqui — vive no painel (`Performer\DashboardController`),
 * que é onde a seção "Fotos recebidas" aparece. Este controller só entrega os
 * bytes, e nem isso ele decide: `readForPerformer()` confere que o acesso é
 * dela, que a foto está viva e que o acesso está vigente, e só depois carimba a
 * visualização.
 */
class ReceivedPhotoController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(private MemberPhotoService $photos) {}

    /**
     * 403 quando o acesso é de OUTRA performer (decisão do PO), 404 quando o
     * prazo venceu de qualquer um dos dois lados.
     *
     * A distinção é pedida de propósito para a tela poder explicar cada caso,
     * mas ela tem um custo que fica registrado aqui: 403 vs 404 diz à performer
     * que aquele id de acesso EXISTE e é de outra pessoa. É informação fraca
     * (não diz de quem para quem), e a mensagem continua uniforme; se um dia
     * incomodar, o conserto é devolver 404 nos dois.
     */
    public function image(Request $request, MemberPhotoAccess $access): Response
    {
        try {
            $bytes = $this->photos->readForPerformer($request->user(), $access);
        } catch (MemberPhotoException $e) {
            abort($e->reason === MemberPhotoException::FORBIDDEN ? 403 : 404);
        }

        return $this->photoResponse($bytes);
    }
}
