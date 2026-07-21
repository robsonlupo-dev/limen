<?php

/*
|--------------------------------------------------------------------------
| Filtro de conteúdo do chat
|--------------------------------------------------------------------------
| Termos que barram o ENVIO de uma mensagem. Dois alvos: combinar encontro
| presencial (que é o que transforma a plataforma em intermediação de
| prostituição, o risco que FOSTA-SESTA pune) e levar a transação para fora,
| que tira o dinheiro do ledger e a performer da proteção da plataforma.
|
| ⚠️ O QUE ESTE FILTRO NÃO É:
|
| 1. **Não é anti-evasão.** Quem quer contornar escreve "wh4ts", "z a p",
|    "meu núm3ro" ou combina em código na terceira mensagem. A normalização
|    (acentos, leet, repetição) encarece o desvio óbvio e é só isso. Tratar a
|    ausência de bloqueio como prova de que ninguém combinou encontro é o
|    erro que este arquivo pede para não cometer.
|
| 2. **Não é neutro em falso positivo.** Ver o bloco AMBÍGUOS abaixo — há
|    termos aqui que aparecem em conversa legítima todo dia. Cada falso
|    positivo é um membro que pagou 50 tokens pelo acesso, levou um "mensagem
|    não permitida" sem explicação (a mensagem é genérica de propósito) e não
|    tem como saber o que fazer diferente.
|
| A lista é configurável justamente para ser ajustada com dado de produção:
| olhe `audit_logs` em `chat.message_blocked` e o `term_hash` antes de
| acrescentar termo novo.
*/

return [

    'enabled' => (bool) env('CHAT_FILTER_ENABLED', true),

    /*
    | Termos barrados. Comparados sobre o texto NORMALIZADO (minúsculas, sem
    | acento, leet desfeito) e sempre com fronteira de palavra — 'zap' não
    | casa dentro de 'zapping', 'fone' não casa dentro de 'telefone'.
    | Termo com espaço vira "espaço flexível": 'pix fora' casa 'pix   fora'.
    */
    'terms' => [

        // ── Encontro presencial ──────────────────────────────────────────
        'presencial',
        'hotel',
        'motel',
        'endereço',

        // ── Contato fora da plataforma ───────────────────────────────────
        // Levar a conversa para fora tira o par do canal pago E do registro
        // que protege a performer numa denúncia.
        'whatsapp',
        'whats',
        'zap',
        'telefone',
        'celular',
        'fone',
        'telegram',
        'instagram',

        // ── Transação fora do ledger ─────────────────────────────────────
        // Frases, não palavras soltas: 'pix' sozinho é o meio de pagamento da
        // própria plataforma e barrá-lo quebraria a conversa sobre a compra
        // legítima de tokens.
        'pix fora',
        'pix direto',
        'fora da plataforma',
        'fora do site',
        'transferência bancária',
        'conta bancária',

        /*
        | ── AMBÍGUOS — alto falso positivo ──────────────────────────────
        |
        | Estes vieram na especificação do Sprint 6 e estão ligados como
        | pedido, mas são palavras comuns do português coloquial e vão
        | barrar conversa legítima:
        |
        |   'conta'      → "me conta", "conta comigo", "por minha conta"
        |   'banco'      → "sentei no banco"
        |   'encontro'   → "eu te encontro depois", 1ª pessoa de encontrar
        |   'transferência' → contexto financeiro legítimo
        |
        | As formas ÚTEIS delas já estão cobertas acima como frase
        | ('conta bancária', 'transferência bancária'), que é onde o sinal
        | de verdade está. Desligar as quatro linhas abaixo mantém a
        | cobertura e devolve a conversa normal.
        |
        | Recomendação da implementação: manter comentadas. Estão explícitas
        | e não deletadas para a decisão ser do PO, não minha.
        */
        // 'conta',
        // 'banco',
        // 'encontro',
        // 'transferência',
    ],

];
