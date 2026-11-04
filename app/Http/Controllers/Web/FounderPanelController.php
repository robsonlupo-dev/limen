<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use Illuminate\Http\RedirectResponse;

class FounderPanelController extends Controller
{
    /**
     * /f/{invite_code} — o painel antigo virou um REDIRECT para o painel
     * unificado (/lista/continuar, modo público via ?c=): uma tela só para
     * continuação + nível/metas + convite (unificação de 04/11, decisão do PO).
     * A rota fica viva porque os e-mails antigos apontam para cá. O invite_code
     * é público (circula no /convite/), então este caminho NUNCA ganha o token
     * de dono — quem chega por aqui vê o modo público; o dono entra pelos links
     * do próprio e-mail (que carregam e+k) ou pela sessão de quem acabou de se
     * inscrever.
     */
    public function show(string $invite_code): RedirectResponse
    {
        $entry = WaitlistEntry::findByInviteCode($invite_code);

        abort_if($entry === null, 404);

        return redirect()->route('waitlist.continue', ['c' => $entry->invite_code]);
    }
}
