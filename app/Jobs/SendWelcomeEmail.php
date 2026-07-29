<?php

namespace App\Jobs;

use App\Mail\WelcomeFounderEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Carta dos fundadores, uma vez por conta.
 *
 * **Toda a decisão de enviar vive aqui**, e não no chamador. `KycService::approve()`
 * é alcançado por três caminhos (webhook do Didit, painel admin web, admin da
 * API) e o job ainda pode ser repetido pela fila; espalhar a checagem pelos
 * chamadores criaria três cópias e a quarta nasceria sem trava.
 */
class SendWelcomeEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function handle(): void
    {
        // Marca ANTES de enviar, dentro da transação e sob lock. É "no máximo
        // uma vez", escolhido de propósito: entre repetir a carta e, no caso
        // raro de o Resend falhar exatamente aqui, não mandá-la, o dano maior é
        // repetir. Uma apresentação pessoal que chega duas vezes desmente o
        // próprio tom, e o destinatário não tem como saber que foi um retry.
        //
        // O lockForUpdate é o que fecha a corrida real: dois workers pegando o
        // job (reaprovação + retry) leriam `null` no mesmo instante e os dois
        // enviariam. `whereNull` no UPDATE seria alternativa, mas o lock
        // mantém a leitura e a escrita na mesma janela e lê melhor.
        $recipient = DB::transaction(function () {
            $user = User::whereKey($this->user->id)->lockForUpdate()->first();

            if ($user === null) {
                return null;
            }

            // Admin nunca recebe (decisão do PO). Um admin não passa pelo KYC
            // no fluxo normal, mas a checagem fica aqui — junto das outras — em
            // vez de depender de nenhum caminho futuro alcançar este job.
            if ($user->role === 'admin') {
                return null;
            }

            if ($user->welcome_email_sent_at !== null) {
                return null;
            }

            // forceFill: a coluna está FORA do $fillable de propósito (mesma
            // regra de discrete_mode e do 2FA) — é trava interna, nunca payload.
            $user->forceFill(['welcome_email_sent_at' => now()])->save();

            return $user;
        });

        if ($recipient === null) {
            return;
        }

        Mail::to($recipient->email)->send(new WelcomeFounderEmail($recipient));
    }
}
