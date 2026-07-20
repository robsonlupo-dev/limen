<?php

/**
 * Versão vigente dos documentos que a performer precisa aceitar.
 *
 * A versão é a data de publicação do texto jurídico (ISO, "2026-07-20"), não um
 * contador: o aceite guardado no banco é comparado com o valor daqui, então
 * BUMPAR A VERSÃO FORÇA RE-ACEITE de todas as performers — é assim que a troca
 * do texto pelo escritório vira evidência nova em vez de silenciosamente cobrir
 * um aceite feito sobre outro texto.
 *
 * Corolário: não bumpe por correção de typo. Toda mudança aqui derruba a
 * plataforma inteira na tela de aceite até cada performer reaceitar.
 */
return [

    'versions' => [
        'content_policy' => env('DOC_VERSION_CONTENT_POLICY', '2026-07-20'),
        'performance_contract' => env('DOC_VERSION_PERFORMANCE_CONTRACT', '2026-07-20'),
    ],

];
