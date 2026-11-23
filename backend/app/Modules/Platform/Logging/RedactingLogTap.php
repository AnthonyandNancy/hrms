<?php

declare(strict_types=1);

namespace App\Modules\Platform\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Appended to every configured Monolog channel. It rewrites context and
 * message before handlers receive the record, so file, stderr and syslog all
 * share the same redaction rules.
 */
final class RedactingLogTap
{
    public function __invoke(Logger $logger): void
    {
        $underlying = $logger->getLogger();

        if (! method_exists($underlying, 'pushProcessor')) {
            return;
        }

        $redactor = new SensitiveDataRedactor;

        $underlying->pushProcessor(static function (LogRecord $record) use ($redactor): LogRecord {
            return $record->with(
                message: $redactor->message($record->message),
                context: $redactor->context($record->context),
                extra: $redactor->context($record->extra),
            );
        });
    }
}
