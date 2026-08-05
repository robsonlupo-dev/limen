<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:reconcile')->everyTenMinutes();

// withoutOverlapping: a slow Asaas run must not stack two reconciles and pile on
// rate-limit pressure (a 429 mid-reconcile must never be read as a failed transfer).
//
// O expiry de 15min é o que impede o lock de virar um deadlock silencioso: sem
// ele o default é 24h, e um SIGKILL no meio da execução (restart de deploy, OOM)
// deixaria o lock preso no cache. O reconcile pararia sem ninguém perceber — e
// ele é a única rede de segurança de um design que, de propósito, nunca estorna
// em estado ambíguo: os payouts ficariam em 'processing' com tokens reservados.
//
// 15min cobre com folga uma execução normal, mas NÃO é um limite de runtime: o
// loop é sequencial e cada payout pode gastar até 2 chamadas de 20s, então um
// lote de ~45 payouts pendurados estoura os 15min e o lock expira no meio da
// execução, permitindo um segundo reconcile concorrente. Dinheiro segue seguro
// (markPaid/markFailedAndReverse são transacionais, com lockForUpdate e guarda
// de status), mas os dois processos dobram a pressão de rate limit. Se o lote
// crescer a esse ponto, a saída é limitar o lote/paralelismo — aumentar o
// expiry só troca a corrida pelo deadlock que ele veio evitar.
Schedule::command('payouts:reconcile')->everyTenMinutes()->withoutOverlapping(15);

// Sweep mensal do payout (M.10/M.13.5): no dia 1, paga os ganhos devidos do mês
// que fechou. É idempotente por (performer, ano, mês) — índice único + checagem —,
// então rodar 2x no mesmo mês (agendado + manual) nunca duplica. Roda às 02:00,
// depois do grant mensal, para o saldo do fechamento já estar consolidado.
// withoutOverlapping por segurança; a idempotência real é do índice único.
Schedule::command('payouts:process-monthly')->monthlyOn(1, '02:00')->withoutOverlapping(60);

// Nurturing drip: hourly is fine — cadence is measured in days, and the sender
// is idempotent, so a step goes out at most once regardless of how often it runs.
Schedule::command('waitlist:send-nurture')->hourly();

// Aplica o cancel_at_period_end: encerra no Asaas e aqui as assinaturas cujo
// período pago acabou. De hora em hora porque o atraso custa dinheiro do membro —
// enquanto a assinatura viver no gateway, ela cobra o ciclo seguinte.
// withoutOverlapping: uma rodada lenta (lote grande, Asaas devagar) não pode
// empilhar com a próxima e mandar dois cancelamentos da mesma linha.
Schedule::command('subscriptions:expire')->hourly()->withoutOverlapping(10);

// Reconciliação da franquia mensal dos Círculos (M.13.4/M.13.8). O caminho
// PRIMÁRIO é o webhook de cobrança, que concede na hora do pagamento; este
// command é a rede de segurança para um ciclo que rodou sem o webhook conceder.
// De hora em hora (não diário): o corte de ciclo é o datetime de cada assinante,
// então um daily atrasaria a franquia de alguém em até um dia. Os dois caminhos
// compartilham last_grant_period_start, então o command NUNCA reconcede o que o
// webhook já concedeu — a serialização real é o lock por linha + a marca, não
// este withoutOverlapping (que só impede duas rodadas DO COMMAND de empilharem).
Schedule::command('subscriptions:grant-monthly')->hourly()->withoutOverlapping(15);

// Expiração/retenção do chat: diário. Marca acessos vencidos e, passada a
// carência, soft-deleta as mensagens (retidas no servidor). Prazos em dias, então
// diário basta; withoutOverlapping evita duas varreduras concorrentes soft-
// deletando o mesmo lote.
Schedule::command('chat:purge-expired-access')->dailyAt('03:30')->withoutOverlapping(10);

// Direito de eliminação (LGPD art. 18, VI): executa as exclusões cuja carência
// de 30 dias venceu. Diário basta — o prazo é contado em dias, e adiantar não é
// opção (a carência é justamente o direito de desistir).
// withoutOverlapping: executeDeletion destrói arquivos de KYC do disco, e esse
// passo é o único fora da transação — duas varreduras no mesmo usuário fariam a
// segunda apagar caminhos já removidos e falhar o lote por um erro inócuo.
Schedule::command('deletions:process')->dailyAt('04:00')->withoutOverlapping(30);

// Fotos efêmeras do membro: recolhe do disco o que já venceu. De hora em hora
// porque o TTL mais curto do menu é 24h e o dado é o rosto de quem enviou —
// deixar os bytes um dia inteiro além do prazo seria o contrário do produto.
//
// Isto é GC, não a expiração: quem nega a foto vencida é a leitura, no
// MemberPhotoService (docs/SECURITY_ISSUES.md § 1.3). Uma rodada perdida custa
// disco, nunca acesso indevido.
//
// withoutOverlapping: a rodada apaga arquivo do disco e só então soft-deleta a
// linha — duas varreduras concorrentes fariam a segunda tropeçar em caminhos que
// a primeira já removeu e contariam falha onde não houve. Mesmo motivo do
// `deletions:process`.
Schedule::command('member-photos:purge')->hourly()->withoutOverlapping(10);

// Stories da performer: recolhe do disco o que já venceu. De hora em hora porque
// o prazo é fixo em 24h e o produto promete que o conteúdo some — deixar os bytes
// um dia inteiro além do prazo seria o contrário do que a feature vende.
//
// Isto é GC, não a expiração: quem nega o story vencido é a leitura, pelo escopo
// `active()`/`isExpired()` (docs/SECURITY_ISSUES.md § 2.8). Uma rodada perdida
// custa disco, nunca acesso indevido.
//
// A rodada PULA story com denúncia em aberto (§ 2.4): o auto-delete de 24h seria,
// senão, o botão de destruir a prova contra si que o DeletionService recusa dar ao
// denunciado.
//
// withoutOverlapping: a rodada apaga arquivo do disco e só então solta a linha —
// duas varreduras concorrentes fariam a segunda tropeçar em caminhos que a
// primeira já removeu e contariam falha onde não houve. Mesmo motivo do
// `member-photos:purge`.
Schedule::command('stories:purge')->hourly()->withoutOverlapping(10);

// Retenção das visitas a perfis: o painel mostra 24h, guardamos 7 dias. Diário
// basta — o prazo é em dias, e um DELETE por faixa de data não disputa nada com
// o resto da madrugada.
Schedule::command('visits:purge')->dailyAt('04:30')->withoutOverlapping(10);

// Códigos OTP de login vencidos: GC de credencial efêmera. De hora em hora
// porque o TTL é de 5 min — a expiração já vale na leitura (isConsumable), então
// isto só evita acúmulo de linhas mortas. Um DELETE por faixa de data, sem
// disputa com nada.
Schedule::command('otp:purge')->hourly()->withoutOverlapping(10);

// Pedidos de chamada 1:1 pendentes vencidos (Sprint 15). A expiração já vale na
// leitura (reconcilePending no request/accept); isto é só faxina para o pending
// sem resposta não sobrar. TTL de 60s, mas de hora em hora basta — a leitura já
// não deixa um pending vencido bloquear nem ser aceito.
Schedule::command('calls:expire-pending')->hourly()->withoutOverlapping(10);
