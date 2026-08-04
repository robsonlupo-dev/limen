<?php

/**
 * Fonte canônica das invariantes da economia de tokens — emenda M.13 do CLAUDE.md
 * (fechada pelo PO em 03/08/2026). TODO número de pacote, franquia, desconto, teto,
 * split, piso, presente, payout e chat que M.13 fixa mora AQUI, e é lido pela
 * `App\Services\TokenCreditPolicy` (a dona única das regras). Nenhuma outra classe
 * decide teto, split, crédito de chat ou piso.
 *
 * Dinheiro em CENTAVOS, token em INTEIRO. Nunca float no ledger (M.13.7).
 *
 * ⚠️ Estado de implementação (M.13.12): este é o PR das INVARIANTES. Os valores de
 * pacote/franquia/desconto aqui são a referência canônica; a APLICAÇÃO deles na
 * cobrança Asaas e a migração das tabelas `token_packages`/`circles` (que hoje
 * ainda guardam os números pré-M.13) são PRs seguintes. Os testes deste PR travam
 * as invariantes contra ESTE arquivo.
 */

return [

    /*
    | Pacotes de tokens (M.13.2) — preço cheio, âncora de R$1,00/token no Starter.
    | price_cents em centavos; tokens inteiro.
    */
    'packages' => [
        'starter' => ['price_cents' => 4990, 'tokens' => 50],
        'popular' => ['price_cents' => 9990, 'tokens' => 105],
        'premium' => ['price_cents' => 19990, 'tokens' => 220],
        'vip' => ['price_cents' => 49990, 'tokens' => 580],
    ],

    /*
    | Desconto por tier sobre a COMPRA de pacote avulso (M.13.3), em % inteiro.
    | Invariante (M.13.11): nenhuma combinação pacote+desconto pode cair abaixo de
    | R$0,625/token (margem mínima 25%). Travado por teste parametrizado (20 combos).
    */
    'discounts_by_tier' => [
        'explorador' => 10,
        'insider' => 10,
        'prestige' => 15,
        'black' => 20,
        'founders_circle' => 25,
    ],

    /*
    | Franquia mensal de tokens inclusos por Círculo (M.13.4). Creditada como
    | subscription_grant, sujeita ao teto (M.13.8). É também a base do teto
    | escalonado e do gatilho de aviso.
    */
    'franchises_by_tier' => [
        'explorador' => 105,
        'insider' => 230,
        'prestige' => 490,
        'black' => 1000,
        'founders_circle' => 2100,
    ],

    /*
    | Teto de acúmulo (M.13.8/M.13.9). Regra: max(default, 4 × franquia), com FC
    | fixado em 8000 pelo PO. Para todo tier não-FC, 4 × franquia < 5000, então o
    | teto é 5000; só FC sobe para 8000. `overrides` guarda a exceção do PO.
    | saldo > teto é estado LEGÍTIMO — não há constraint de banco (M.13.9).
    */
    'cap' => [
        'default' => 5000,
        'multiplier' => 4,
        'overrides' => [
            'founders_circle' => 8000,
        ],
        // Gatilho de aviso do não-assinante (M.13.8): 2×franquia não existe sem
        // tier, então o piso é fixo. Espaço_restante ≤ este valor → avisar.
        'warning_remaining_no_tier' => 4500,
    ],

    /*
    | Split percentual por TIPO DE EVENTO, nunca por lugar/pessoa (M.13.6/M.13.7).
    | Taxa em INTEIRO, gravada e congelada na linha do ledger (applied_rate). A
    | leitura nunca recalcula: o `amount` da linha é a fonte de verdade; a taxa é
    | auditoria. `effective_from` documenta a vigência (M.13.7 item 6).
    | Chat NÃO está aqui: é crédito FIXO de 1 token, fora do percentual (M.13.1).
    */
    'split_rates' => [
        'content' => ['rate' => 80, 'effective_from' => '2026-08-03'],
        'tip' => ['rate' => 80, 'effective_from' => '2026-08-03'],
        'gift' => ['rate' => 75, 'effective_from' => '2026-08-03'],
        'live' => ['rate' => 70, 'effective_from' => '2026-08-03'],
        'call' => ['rate' => 70, 'effective_from' => '2026-08-03'],
    ],

    /*
    | Piso de 5 tokens em conteúdo/live/chamada (M.13.7 item 5). A validação de
    | input entra com cada feature; a constante e o teste do piso entram já.
    */
    'content_floor' => 5,

    /*
    | Conteúdo permanente (M.4): o preço definido pela performer deve ser múltiplo
    | de `price_step` tokens (passo de 5), além do piso de `content_floor` (5).
    | Validado na PUBLICAÇÃO (não no unlock, onde o preço já está gravado).
    */
    'content_price_step' => 5,

    /*
    | Presentes (M.13.6): preço DEVE ser múltiplo de 4 tokens. Só a validação e a
    | constante entram neste PR; catálogo e animação são PR posterior.
    */
    'gift_price_multiple' => 4,

    /*
    | Payout (M.13.5): cada token vale R$0,60 no saque, sempre. Constante em REAIS
    | (não centavos) porque é a taxa de conversão exibida à performer. O ciclo
    | mensal de payout é PR posterior; aqui entra só a constante + o teste.
    | Redação obrigatória na UI da performer (M.13.5): "Você recebe 80% dos tokens
    | da transação. Cada token vale R$0,60 no saque." NUNCA % sobre reais.
    */
    'payout_rate_per_token' => 0.60,

    /*
    | Payout mensal (M.10 + M.13.5). Mínimo/máximo de tokens por saque (vale para
    | o sweep automático do dia 1 E o saque on-demand). `earning_entry_types` é o
    | allowlist ESTRITO do que conta como ganho sacável: NUNCA por sinal do amount
    | (senão bonus/refund/subscription_grant/purchase vazariam a R$0,60 — leak).
    | Tipos futuros (content_credit, gift_credit, live_credit, call_credit) entram
    | aqui quando as features shiparem. payout_reserve/payout_reversal NÃO entram:
    | são o controle do próprio saque, não ganho.
    */
    'payout' => [
        'min_tokens' => 100,
        'max_tokens' => 50000,
        'earning_entry_types' => [
            'tip_credit',
            'chat_access_credit',
            // Conteúdo permanente (PR #135): a receita de desbloqueio é ganho
            // sacável da performer, então entra no payout (M.13.5). live_credit/
            // call_credit entram quando essas features shiparem.
            'content_credit',
            // Presentes virtuais (PR #137, M.13.6): a fatia da performer (75%) é
            // ganho sacável, então entra no payout como o content_credit.
            'gift_credit',
        ],
    ],

    /*
    | Chat (M.13.1): crédito FIXO de 1 token à performer em toda abertura, qualquer
    | tier, fora do split. Custo do membro por tier. ⚠️ Black e Expl/Ins/Pres
    | MUDARAM vs. o modelo antigo — M.13.1 é a referência. Neste PR isto é SINAL
    | (config + método da policy + teste); o rewire do ChatAccessService (que hoje
    | assume "assinante = chat livre") é o PR de chat.
    */
    'chat' => [
        'performer_credit' => 1,
        'cost_by_tier' => [
            'none' => 2,
            'explorador' => 2,
            'insider' => 2,
            'prestige' => 2,
            'black' => 1,
            'founders_circle' => 1,
        ],
    ],

    /*
    | Margem mínima (M.13.11): custo efetivo por token nunca abaixo disto (R$).
    | Usado pelo teste das 20 combinações pacote×desconto.
    */
    'min_effective_price_per_token' => 0.625,

    /*
    | Tipos de crédito que RESPEITAM o teto (M.13.9). A chave é o entry_type, NUNCA
    | o role do dono da carteira. Todo o resto (tip_credit, chat_access_credit,
    | refund, payout_reversal, adjustment, todo *_credit futuro) NUNCA respeita.
    */
    'cap_respecting_entry_types' => [
        'purchase',
        'bonus',
        'subscription_grant',
    ],

];
