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
        // 2026-10-16: texto real (minuta pré-lançamento) substitui o placeholder de
        // 2026-07-20 — bump deliberado, força o re-aceite das Performers.
        'content_policy' => env('DOC_VERSION_CONTENT_POLICY', '2026-10-16'),
        'performance_contract' => env('DOC_VERSION_PERFORMANCE_CONTRACT', '2026-10-16'),

        // Documentos públicos SEM aceite registrado (não entram em
        // DocumentAcceptance::REQUIRED): a versão só rotula a página. Mudar estes
        // NÃO derruba ninguém na tela de aceite.
        'terms' => env('DOC_VERSION_TERMS', '2026-10-16'),
        'privacy' => env('DOC_VERSION_PRIVACY', '2026-10-16'),
        'age_verification' => env('DOC_VERSION_AGE_VERIFICATION', '2026-10-16'),
    ],

];
