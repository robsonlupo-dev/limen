<?php

/**
 * Textos jurídicos públicos (resources/legal/*.md) — configuração de exibição.
 *
 * `draft` liga a faixa "versão pré-lançamento, em revisão por advogado" no topo
 * de cada documento. Desligue (LEGAL_DRAFT=false) SÓ depois que um advogado
 * revisar o texto — enquanto estiver ligada, a página não afirma ser a versão
 * definitiva (ver docs/juridico/00_LEIA-ME_PARA_O_ADVOGADO.md).
 *
 * `contact_email` substitui `{{CONTATO}}` nos textos (canal do encarregado de
 * dados/denúncias/suporte). Sem valor, o texto cai para "o canal de contato da
 * plataforma" — configure LEGAL_CONTACT_EMAIL no .env antes de divulgar.
 */
return [

    'draft' => (bool) env('LEGAL_DRAFT', true),

    'contact_email' => env('LEGAL_CONTACT_EMAIL'),

];
