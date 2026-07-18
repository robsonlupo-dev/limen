<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth for Asaas webhooks: rejects requests whose source IP is not
 * on the configured allowlist. Layered on top of the asaas-access-token check
 * in the controller — a stolen token alone no longer suffices when this is on.
 *
 * Off by default (see config/asaas.php): sandbox/staging receive posts from IPs
 * outside the published production set, so the allowlist is a production-only
 * switch (ASAAS_WEBHOOK_IP_ALLOWLIST=true).
 */
class VerifyAsaasWebhookIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('asaas.webhook_ip_allowlist_enabled')) {
            return $next($request);
        }

        $allowed = config('asaas.webhook_allowed_ips', []);

        // IpUtils::checkIp supports both plain IPs and CIDR ranges, matching the
        // config format documented in config/asaas.php.
        if (! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            Log::warning('asaas.webhook.ip_blocked', ['ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
