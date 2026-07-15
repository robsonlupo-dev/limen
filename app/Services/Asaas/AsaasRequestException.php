<?php

namespace App\Services\Asaas;

use RuntimeException;

/**
 * A definitive client-side failure (HTTP 4xx or an invalid request built before
 * sending). The Asaas request was rejected and NOT processed, so the caller can
 * safely treat the operation as failed (e.g. reverse a token reservation).
 */
class AsaasRequestException extends RuntimeException {}
