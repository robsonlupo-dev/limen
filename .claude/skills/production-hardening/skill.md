# Skill: production-hardening

## .env de produção (template)
```env
APP_NAME="Limen"
APP_ENV=production
APP_KEY=           # php artisan key:generate
APP_DEBUG=false    # CRÍTICO: nunca true em produção
APP_URL=https://limen.com.br
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error    # só erros em produção (não debug/info)

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=limen
DB_USERNAME=limen
DB_PASSWORD=[SENHA_FORTE_GERADA]

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=smtp.zoho.com
MAIL_PORT=587
MAIL_USERNAME=contato@limen.com.br
MAIL_PASSWORD=[SENHA_ZOHO]
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=contato@limen.com.br
MAIL_FROM_NAME="Limen"

ASAAS_ENV=production
ASAAS_BASE_URL=https://api.asaas.com/api/v3
ASAAS_API_KEY=[CHAVE_PRODUCAO_ASAAS]
ASAAS_WEBHOOK_TOKEN=[TOKEN_WEBHOOK_PRODUCAO]

KYC_PROVIDER=unico
KYC_BASE_URL=https://api.unico.io
KYC_API_KEY=[CHAVE_UNICO_PRODUCAO]
KYC_WEBHOOK_SECRET=[SECRET_UNICO_PRODUCAO]

VITE_APP_URL=https://limen.com.br
```

## Configurações PHP para produção
```ini
# /etc/php/8.3/fpm/conf.d/99-limen.ini
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php/limen-errors.log
upload_max_filesize = 10M
post_max_size = 11M
max_execution_time = 60
memory_limit = 256M
```

## Middleware de security headers
```php
// app/Http/Middleware/SecurityHeaders.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        
        return $response;
    }
}
```

Registrar em `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
})
```

## Checklist final pré-go-live
- [ ] APP_DEBUG=false confirmado
- [ ] APP_ENV=production confirmado
- [ ] Todas as chaves de produção no .env (Asaas, KYC, Mail)
- [ ] .env NÃO está no Git
- [ ] SSL válido (curl -I https://limen.com.br mostra 200)
- [ ] Redirect HTTP→HTTPS funcionando
- [ ] php artisan config:cache executado
- [ ] php artisan route:cache executado
- [ ] php artisan view:cache executado
- [ ] Queue worker rodando (supervisorctl status)
- [ ] Backup agendado (crontab -l)
- [ ] Uptime monitor configurado
- [ ] DNS propagado (ping limen.com.br resolve para IP correto)
