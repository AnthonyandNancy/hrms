<?php

declare(strict_types=1);

namespace App\Modules\Platform\Features;

use App\Modules\Platform\Models\AuditEvent;
use App\Shared\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Server-side feature gates. The code catalogue is authoritative; the table
 * only stores instance-wide overrides. Unknown flags are denied (default deny).
 * Every block and every override change is audited.
 */
final class FeatureFlagService
{
    /** @var array<string, bool> */
    public const DEFAULTS = [
        'payroll_enabled' => false,
        'time_enabled' => false,
        'self_service_enabled' => false,
        'recruitment_enabled' => false,
        'export_enabled' => false,
    ];

    public function enabled(string $flag): bool
    {
        $this->assertKnown($flag);
        $configured = (bool) config("hrms.feature_flags.{$flag}", self::DEFAULTS[$flag]);
        $override = DB::table('feature_flags')
            ->where('flag', $flag)
            ->value('enabled');

        if ($override !== null) {
            return (bool) $override;
        }

        return $configured;
    }

    /**
     * @return list<string>
     */
    public function flags(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public function set(string $flag, bool $enabled, ?int $actorUserId = null, string $reason = ''): void
    {
        $this->assertKnown($flag);
        $before = $this->enabled($flag);
        $now = CarbonImmutable::now('UTC');

        DB::table('feature_flags')->updateOrInsert(
            ['flag' => $flag],
            [
                'enabled' => $enabled,
                'updated_by' => $actorUserId,
                'reason' => $reason,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        if ($before !== $enabled) {
            AuditEvent::query()->create([
                'actor_user_id' => $actorUserId,
                'actor_type' => $actorUserId === null ? 'SYSTEM' : 'USER',
                'action' => 'feature_flag.changed',
                'aggregate_type' => 'feature_flag',
                'aggregate_id' => $flag,
                'before_json' => ['enabled' => $before],
                'after_json' => ['enabled' => $enabled, 'reason' => $reason],
                'metadata_json' => ['request_id' => RequestContext::requestId()],
                'occurred_at' => $now,
            ]);
        }
    }

    public function auditBlocked(string $flag, string $route): void
    {
        AuditEvent::query()->create([
            'actor_user_id' => RequestContext::userId(),
            'actor_type' => RequestContext::userId() === null ? 'SYSTEM' : 'USER',
            'action' => 'feature_flag.blocked',
            'aggregate_type' => 'feature_flag',
            'aggregate_id' => $flag,
            'after_json' => ['enabled' => false, 'route' => $route],
            'metadata_json' => ['request_id' => RequestContext::requestId()],
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function assertKnown(string $flag): void
    {
        if (! array_key_exists($flag, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown feature flag '{$flag}'.");
        }
    }
}
