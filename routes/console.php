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

// Nurturing drip: hourly is fine — cadence is measured in days, and the sender
// is idempotent, so a step goes out at most once regardless of how often it runs.
Schedule::command('waitlist:send-nurture')->hourly();

// Expiração/retenção do chat: diário. Marca acessos vencidos e, passada a
// carência, soft-deleta as mensagens (retidas no servidor). Prazos em dias, então
// diário basta; withoutOverlapping evita duas varreduras concorrentes soft-
// deletando o mesmo lote.
Schedule::command('chat:purge-expired-access')->dailyAt('03:30')->withoutOverlapping(10);
