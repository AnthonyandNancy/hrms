<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class TalentEffectSupport
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(DomainEffectCommand $command): array
    {
        $payload = $command->payload['case_payload'] ?? null;

        return is_array($payload) ? $payload : [];
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function audit(): AuditRecorder
    {
        return $this->audit;
    }

    public function transactional(\Closure $callback): mixed
    {
        /** @var mixed $result */
        $result = DB::transaction(static function () use ($callback): mixed {
            return $callback();
        });

        return $result;
    }
}
