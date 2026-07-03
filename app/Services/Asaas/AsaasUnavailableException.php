<?php

namespace App\Services\Asaas;

use RuntimeException;

/**
 * An ambiguous outcome: a timeout, connection error, or HTTP 5xx. Asaas MAY have
 * processed the request (e.g. a PIX transfer could already be on its way), so the
 * caller must NOT assume failure — never reverse money on this. Defer to the
 * webhook or a reconciliation pass to determine the real outcome.
 */
class AsaasUnavailableException extends RuntimeException {}
