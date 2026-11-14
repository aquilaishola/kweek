<?php
/**
 * Safety net for merchant webhooks — sends any missed payment.success events
 * and retries failed ones (1m, 5m, 30m, 2h, 12h).
 *
 * cPanel → Cron Jobs → every minute:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/cron/dispatch-webhooks.php >/dev/null 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/integration.php';
$n = kwSweepWebhooks(50);
echo date('c') . " processed $n\n";
