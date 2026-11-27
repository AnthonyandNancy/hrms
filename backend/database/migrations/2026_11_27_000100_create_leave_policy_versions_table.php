<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M10 leave-policy closure.
 *
 * `leave_types` is reduced to identity/display facts going forward. Rules are
 * effective-dated append-only versions. The legacy rule-bearing columns stay
 * in place for read compatibility, but are backfilled once and never written
 * again by Leave application services.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leave_types', 'status')) {
            Schema::table('leave_types', static function (Blueprint $table): void {
                $table->string('status', 16)->default('ACTIVE')->after('version');
            });
        }

        if (! Schema::hasColumn('leave_request_projections', 'actor_user_id')) {
            Schema::table('leave_request_projections', static function (Blueprint $table): void {
                $table->unsignedBigInteger('actor_user_id')->nullable()->after('employment_id')->index();
            });
        }

        if (! Schema::hasColumn('leave_request_projections', 'leave_policy_version_id')) {
            Schema::table('leave_request_projections', static function (Blueprint $table): void {
                $table->unsignedBigInteger('leave_policy_version_id')->nullable()->after('leave_type_id')->index();
            });
        }

        if (! Schema::hasColumn('leave_request_projections', 'org_unit_id')) {
            Schema::table('leave_request_projections', static function (Blueprint $table): void {
                $table->unsignedBigInteger('org_unit_id')->nullable()->after('actor_user_id')->index();
            });
        }

        if (! Schema::hasTable('leave_policy_versions')) {
            Schema::create('leave_policy_versions', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('leave_type_id')->index();
                $table->unsignedInteger('version');
                $table->string('status', 16)->default('PUBLISHED');
                $table->date('effective_from');
                $table->date('effective_to');
                $table->json('applies_to_json');
                $table->json('accrual_json');
                $table->json('carryover_json')->nullable();
                $table->unsignedSmallInteger('min_notice_days')->default(0);
                $table->unsignedSmallInteger('max_consecutive_days')->nullable();
                $table->unsignedSmallInteger('requires_attachment_over_days')->nullable();
                $table->boolean('allow_half_day')->default(true);
                $table->string('legal_basis', 255)->nullable();
                $table->dateTime('published_at', 6);
                $table->unsignedBigInteger('published_by');
                $table->dateTime('retired_at', 6)->nullable();
                $table->string('retire_reason', 500)->nullable();
                $table->dateTime('created_at', 6);

                $table->unique(['leave_type_id', 'version'], 'leave_policy_type_version_unique');
                $table->index(['leave_type_id', 'effective_from'], 'leave_policy_effective_idx');
                $table->index(['leave_type_id', 'status', 'effective_to'], 'leave_policy_state_idx');
            });
        }

        $standardDayMinutes = 480;

        if (Schema::hasTable('legal_entity_calendars') && Schema::hasColumn('legal_entity_calendars', 'standard_day_minutes')) {
            $configured = DB::table('legal_entity_calendars')->orderBy('id')->value('standard_day_minutes');

            if (is_numeric($configured) && (int) $configured > 0) {
                $standardDayMinutes = (int) $configured;
            }
        }

        $types = DB::table('leave_types')
            ->select(['id', 'annual_entitlement_days', 'expiry_rule_json', 'legal_basis', 'created_at'])
            ->orderBy('id')
            ->get();

        foreach ($types as $type) {
            $alreadyBackfilled = DB::table('leave_policy_versions')
                ->where('leave_type_id', (int) $type->id)
                ->where('version', 1)
                ->exists();

            if ($alreadyBackfilled) {
                continue;
            }

            $carryover = null;

            if (is_string($type->expiry_rule_json) && $type->expiry_rule_json !== '') {
                $decoded = json_decode($type->expiry_rule_json, true);
                $carryover = is_array($decoded) ? $decoded : null;
            }

            DB::table('leave_policy_versions')->insert([
                'leave_type_id' => (int) $type->id,
                'version' => 1,
                'status' => 'PUBLISHED',
                'effective_from' => '2026-01-01',
                'effective_to' => '9999-12-31',
                'applies_to_json' => json_encode(['scope' => 'COMPANY'], JSON_THROW_ON_ERROR),
                'accrual_json' => json_encode([
                    'mode' => 'ANNUAL_FIXED',
                    'minutes_per_year' => $this->daysToMinutes($type->annual_entitlement_days, $standardDayMinutes),
                ], JSON_THROW_ON_ERROR),
                'carryover_json' => $carryover !== null
                    ? json_encode($carryover, JSON_THROW_ON_ERROR)
                    : null,
                'min_notice_days' => 0,
                'max_consecutive_days' => null,
                'requires_attachment_over_days' => null,
                'allow_half_day' => true,
                'legal_basis' => $type->legal_basis !== null ? (string) $type->legal_basis : null,
                'published_at' => $type->created_at ?? now(),
                'published_by' => 1,
                'retired_at' => null,
                'retire_reason' => null,
                'created_at' => $type->created_at ?? now(),
            ]);
        }

        if (Schema::hasColumn('leave_types', 'status')) {
            DB::table('leave_types')->whereNull('status')->update(['status' => 'ACTIVE']);
        }
    }

    public function down(): void
    {
        // Forward-only: retired policy history and actor evidence must not be
        // destroyed by an automatic rollback.
    }

    private function daysToMinutes(mixed $days, int $standardDayMinutes): int
    {
        if ($days === null || $days === '') {
            return 0;
        }

        $value = trim((string) $days);

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return 0;
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return $whole * $standardDayMinutes
            + intdiv((int) $fraction * $standardDayMinutes, 100);
    }
};
