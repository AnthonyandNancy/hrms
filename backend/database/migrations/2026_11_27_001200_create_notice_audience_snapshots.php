<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze notice recipients at publication time.
 *
 * Before this table, "audience snapshot" was only the audience predicate; a
 * new employee would silently become a recipient of an already-published
 * notice. M18 requires an immutable recipient set for reads, reminders and
 * exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_audience_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notice_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('recorded_at');
            $table->unique(['notice_id', 'user_id'], 'notice_audience_snapshot_unique');
        });

        $now = now()->utc();
        $published = DB::table('notices')->where('status', 'PUBLISHED')->get(['id', 'audience_json', 'publish_at']);

        foreach ($published as $notice) {
            $audience = json_decode((string) $notice->audience_json, true);
            if (! is_array($audience)) {
                continue;
            }

            $date = $notice->publish_at !== null
                ? CarbonImmutable::parse((string) $notice->publish_at)->toDateString()
                : $now->toDateString();
            $type = strtoupper((string) ($audience['type'] ?? 'ALL'));

            $query = DB::table('users')->where('status', 'ACTIVE')->select('id');

            if ($type === 'ROLE') {
                $roles = array_values(array_filter((array) ($audience['role_codes'] ?? []), 'is_string'));
                $query->whereIn('id', function ($sub) use ($roles, $date): void {
                    $sub->select('user_id')
                        ->from('user_roles')
                        ->whereIn('role_code', $roles === [] ? ['__none__'] : $roles)
                        ->where('valid_from', '<=', $date.' 00:00:00')
                        ->where('valid_to', '>=', $date.' 00:00:00');
                });
            } elseif ($type === 'ORG') {
                $orgIds = array_values(array_filter(array_map('intval', (array) ($audience['org_unit_ids'] ?? []))));
                $query->whereIn('id', function ($sub) use ($orgIds, $date): void {
                    $sub->select('eul.user_id')
                        ->from('employment_user_links as eul')
                        ->join('assignments as a', 'a.employment_id', '=', 'eul.employment_id')
                        ->whereIn('a.org_unit_id', $orgIds === [] ? [-1] : $orgIds)
                        ->where('a.valid_from', '<=', $date)
                        ->where('a.valid_to', '>', $date)
                        ->whereNull('a.superseded_at');
                });
            } else {
                $type = 'ALL';
            }

            foreach ($query->pluck('id') as $userId) {
                DB::table('notice_audience_snapshots')->insertOrIgnore([
                    'notice_id' => (int) $notice->id,
                    'user_id' => (int) $userId,
                    'recorded_at' => $notice->publish_at ?? $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_audience_snapshots');
    }
};
