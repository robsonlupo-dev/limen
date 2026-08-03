<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Um crédito cap-crítico (purchase/bonus) foi barrado por estourar o teto de
 * acúmulo (M.13.8/M.13.9). Lançada pela `TokenCreditPolicy` no gate de compra
 * (antes de criar a cobrança PIX) e no crédito direto de bonus.
 *
 * NÃO é lançada no webhook de pagamento: dinheiro já pago SEMPRE credita, mesmo
 * acima do teto (M.13.9). subscription_grant também não a lança — ele enfileira o
 * excedente (fila de pendência), não recusa.
 */
class CapExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $balance,
        public readonly int $cap,
    ) {
        parent::__construct("Token cap exceeded: crediting {$requested} over balance {$balance} would pass cap {$cap}.");
    }
}
