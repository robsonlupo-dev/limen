<?php

/**
 * Agendamento de chamada (feat/scheduled-call-v1) — parâmetros do ciclo de vida,
 * FECHADOS pelo PO. Os números da ECONOMIA (split do no-show, allowlist de payout)
 * vivem em config/monetization.php (a fonte canônica da economia); aqui ficam só as
 * JANELAS e LIMITES do agendamento. Lidos pelo CallReservationService (dona única
 * das regras) e pelo ProcessCallReservations (cron).
 *
 * Minutos/horas inteiros. Tudo em unidades de tempo, nunca dinheiro.
 */

return [

    // Duração-padrão do slot quando a performer não define (minutos). FLEXÍVEL: se
    // não houver próximo agendamento na sequência, a chamada passa do slot (cobra
    // por minuto enquanto há saldo). Se houver, o slot é teto duro (max_duration).
    'default_slot_minutes' => 15,

    // Buffer entre slots da MESMA performer (minutos) — respiro + anti-colisão.
    'buffer_minutes' => 5,

    // Janela de confirmação da performer (horas antes do horário). Não confirmar
    // até aqui → cancela + refund. Também é o limite do cancelamento GRÁTIS do
    // membro (depois disso, cancelar = no-show do membro, depósito à performer).
    'confirm_window_hours' => 2,

    // Se a reserva foi criada com MENOS de confirm_window_hours de antecedência,
    // a performer tem esta janela reduzida (minutos antes do horário) para confirmar.
    'short_lead_confirm_minutes' => 30,

    // Janela de entrada de cada lado (segundos). A performer entra primeiro (até
    // scheduled_at + join_window). Quando ela entra, o membro tem join_window para
    // entrar; senão, no-show do membro.
    'join_window_seconds' => 120,

    // Aviso não-intrusivo aos dois lados, X minutos antes do horário.
    'reminder_lead_minutes' => 5,

    // Durante uma chamada agendada com PRÓXIMO agendamento na fila, avisa a
    // performer X minutos antes do fim do slot (o membro não vê).
    'next_slot_warning_minutes' => 3,

    // Teto DURO de agendamentos ativos (pending+confirmed) por membro — total, não
    // por performer. Imposto sob lock na linha-âncora do users (como buscas salvas).
    'max_active_per_member' => 5,

    // Antecedência MÍNIMA para agendar (minutos): impede agendar "para já" e furar
    // as janelas de confirmação/aviso. Deve ser > short_lead_confirm_minutes.
    'min_lead_minutes' => 45,

    // Antecedência MÁXIMA para agendar (dias): teto do horizonte de reserva.
    'max_lead_days' => 30,

    // Strikes de no-show da performer que disparam review de admin (needs_review).
    'strike_review_threshold' => 3,

    // Fuso de EXIBIÇÃO/ENTRADA do agendamento (o banco guarda UTC). O horário que o
    // membro digita no picker é interpretado aqui, não em config('app.timezone')
    // (UTC) — mesma disciplina do painel de visitantes (ProfileVisitService).
    'display_timezone' => 'America/Sao_Paulo',

];
