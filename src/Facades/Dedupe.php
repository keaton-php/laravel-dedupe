<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Keaton\Dedupe\Deduper deduper()
 * @method static \Keaton\Dedupe\DedupeResult run(iterable<mixed> $records, ?string $runId = null)
 * @method static list<\Keaton\Dedupe\AuditEntry> audit(string $runId)
 * @method static list<\Keaton\Dedupe\AuditEntry> auditFor(string|int $recordId)
 * @method static int prune(int $olderThanDays)
 *
 * @see \Keaton\Dedupe\Dedupe
 */
class Dedupe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Keaton\Dedupe\Dedupe::class;
    }
}
