<?php

/*
|--------------------------------------------------------------------------
| Filtro de conteúdo do chat
|--------------------------------------------------------------------------
| Duas categorias, com respostas diferentes ao usuário e propósitos
| diferentes. O que NÃO está aqui é tão importante quanto o que está.
|
| ── O que este filtro deliberadamente NÃO barra (decisão do PO, Sprint 6) ──
|
| 1. **Troca de contato.** WhatsApp, telefone, Instagram, endereço: legítimo
|    numa plataforma de conteúdo adulto/dating. Barrar isso tratava o usuário
|    como suspeito por querer conversar, e na revisão anterior estava barrando
|    "comprei um fone de ouvido" e "vi seu instagram".
|
| 2. **Palavrão em contexto sexual consentido.** É uma plataforma adulta: "que
|    puta gostosa" é o vocabulário do produto, não assédio. Só insulto
|    DIRECIONADO entra (ver `conduct.directed_insults`).
|
| 3. **Combinar encontro SEM valor monetário.** A plataforma não controla a
|    vida pessoal de adultos. "Vamos num motel" passa; "motel, 300 reais" não.
|
| ── O que ele também NÃO é ──
|
| **Não é anti-evasão, e o segredo nunca foi real.** A lista está no repo, e o
| remetente distingue as categorias pela resposta — é enumerável por tentativa
| e erro. A mensagem de erro é específica de propósito: dizer à pessoa o que
| ela violou vale mais do que uma vaguidade que ela contorna em duas tentativas
| de qualquer jeito.
|
| Calibre com dado: `audit_logs` em `chat.message_blocked`, campo `rule_hash`.
*/

return [

    'enabled' => (bool) env('CHAT_FILTER_ENABLED', true),

    /*
    |----------------------------------------------------------------------
    | TIPO 1 — Risco legal (bloqueia)
    |----------------------------------------------------------------------
    | Intermediação de encontro mediante pagamento e transação fora do
    | ledger. É o que FOSTA-SESTA pune e o que tira a performer da proteção
    | (e do registro) da plataforma.
    */
    'legal' => [

        /*
        | Frases inequívocas por si só. São FRASES, não palavras: 'programa'
        | sozinho é "programa de TV", "qual seu programa favorito", "fazer um
        | programa juntos" — barrá-lo solto repetiria o erro de 'conta'.
        */
        'phrases' => [
            'programa completo',
            'fazer programa',
            'faz programa',
            'gfe',
            'girlfriend experience',
            'pix fora',
            'pix direto',
            'transfere fora',
            'transferir fora',
            'paga fora',
            'pagar fora',
            'pagamento fora',
            'fora da plataforma',
            'fora do site',
            'fora do app',
            'por fora do site',
        ],

        /*
        | Termos AMBÍGUOS que só bloqueiam junto de um sinal de dinheiro.
        | É o modelo que a spec do Sprint 6 já descrevia para "encontro
        | presencial + valor", generalizado — porque é o único jeito de usar
        | 'programa' e 'encontro' sem barrar conversa normal.
        |
        | "vamos num motel" → passa.  "motel, 300 reais" → bloqueia.
        */
        'requires_money' => [
            'programa',
            'encontro presencial',
            'encontro pessoalmente',
            'nos encontrar',
            'te encontrar',
            'motel',
            'hotel',
            'presencial',
            'pernoite',
            // 'cachê' fica FORA daqui: ele já é sinal de dinheiro logo abaixo,
            // e nas duas listas casaria consigo mesmo — um bloqueio de palavra
            // solta entrando pela porta dos fundos. Cachê de show NA plataforma
            // é conversa legítima entre performer e membro.
        ],

        /*
        | Sinais de valor monetário. Só contam na MESMA mensagem que um termo
        | de `requires_money`.
        |
        | Números soltos ficam de fora: "te encontro às 300" não existe, mas
        | "nos encontrar dia 15" existe, e um \d+ genérico barraria data,
        | hora e idade. O sinal tem que ser explicitamente de dinheiro.
        */
        'money_signals' => [
            'r$',
            'reais',
            'real',
            'conto',
            'contos',
            'pila',
            'valor',
            'preço',
            'quanto custa',
            'quanto cobra',
            'quanto fica',
            'cachê',
            'diária',
            'taxa',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | TIPO 2 — Conduta abusiva (bloqueia + marca para moderação)
    |----------------------------------------------------------------------
    | Marcado com `flagged_for_review` no audit. NÃO grava o corpo da
    | mensagem (decisão do PO): a moderação age por REPETIÇÃO — "este usuário
    | disparou conduta 9 vezes hoje" — e não por julgamento do caso isolado.
    | Gravar o corpo criaria uma segunda cópia do conteúdo privado do chat em
    | `audit_logs`, que sobrevive ao Hard Delete do LGPD.
    */
    'conduct' => [

        /*
        | Ameaça e sextorsão. Inequívocas — bloqueiam sozinhas, sem depender
        | de contexto.
        |
        | São frases fechadas, nunca prefixos: 'vou te' apareceu na spec, mas
        | barrá-lo mataria "vou te ligar", "vou te mandar foto" e "vou te
        | comer" — sendo que o último é o produto funcionando.
        */
        'threats' => [
            'te mato',
            'vou te matar',
            'vou te bater',
            'vou te machucar',
            'vou acabar com voce',
            'vou acabar com a sua vida',
            'te processo',
            'vou te processar',
            'sei onde voce mora',
            'sei onde voce trabalha',
            'descobri seu endereco',
            'vou na sua casa',
            // Sextorsão — o vetor de ameaça mais próprio desta plataforma.
            'vou vazar suas fotos',
            'vou vazar seus videos',
            'vou espalhar suas fotos',
            'vou te expor',
            'vou mostrar pro seu marido',
            'vou mostrar pra sua familia',
            'vou contar pro seu trabalho',
        ],

        /*
        | Insulto DIRECIONADO: só casa com pronome/possessivo antes
        | (ver ChatContentFilter::directedInsultPattern).
        |
        | 'puta' solto NÃO entra: "que puta gostosa", "puta merda" e "tá puta
        | comigo?" são fala normal aqui. O que muda o sentido é o
        | direcionamento — "sua puta nojenta", "você é uma vaca".
        */
        'directed_insults' => [
            'puta',
            'vadia',
            'vaca',
            'viada',
            'viado',
            'bicha',
            'piranha',
            'rapariga',
            'nojenta',
            'nojento',
            'lixo',
            'imunda',
            'imundo',
            'burra',
            'burro',
            'idiota',
            'retardada',
            'retardado',
            'feia',
            'feio',
            'gorda nojenta',
        ],

        /*
        | Qualificadores que NEUTRALIZAM o insulto direcionado: aparecendo
        | junto, a leitura é dirty talk consensual, não agressão.
        |
        | "sua puta safada" passa; "sua puta nojenta" não. É heurística, e
        | erra no elogio seco ("sua puta") — o falso positivo aceito ao
        | escolher esta abordagem. O caminho para reduzi-lo não é ampliar
        | esta lista indefinidamente, é a denúncia (Report), que tem contexto
        | e um humano do outro lado.
        */
        'consensual_qualifiers' => [
            'safada',
            'safado',
            'gostosa',
            'gostoso',
            'linda',
            'lindo',
            'delicia',
            'deliciosa',
            'delicioso',
            'maravilhosa',
            'maravilhoso',
            'perfeita',
            'perfeito',
            'tesuda',
            'tesudo',
            'sexy',
            'minha',
            'meu',
            'amor',
            'princesa',
        ],
    ],

    /*
    | Janela (minutos) de deduplicação do audit por (usuário, regra).
    | Sem isso, enumerar a lista escreve uma linha por tentativa e enterra a
    | trilha — o mesmo cuidado que config/geo.php já toma.
    */
    'audit_dedup_minutes' => (int) env('CHAT_FILTER_AUDIT_DEDUP_MINUTES', 10),

];
