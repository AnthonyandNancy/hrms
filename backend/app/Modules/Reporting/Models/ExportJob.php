<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Async export state. download_token_hash is a SHA-256 digest; the plaintext
 * token is returned once at creation and never stored.
 */
final class ExportJob extends Model
{
    protected $table = 'export_jobs';

    /** @var list<string> */
    protected $fillable = [
        'report_code',
        'purpose',
        'fields_json',
        'filters_json',
        'status',
        'row_count',
        'source_hash',
        'field_whitelist_json',
        'audit_trail_json',
        'file_object_id',
        'expires_at',
        'download_count',
        'max_downloads',
        'requested_by',
        'download_token_hash',
        'download_token_expires_at',
        'watermark',
        'part_no',
        'total_parts',
        'parent_job_id',
        'failure_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fields_json' => 'array',
            'filters_json' => 'array',
            'row_count' => 'integer',
            'field_whitelist_json' => 'array',
            'audit_trail_json' => 'array',
            'file_object_id' => 'integer',
            'expires_at' => 'immutable_datetime',
            'download_count' => 'integer',
            'max_downloads' => 'integer',
            'requested_by' => 'integer',
            'download_token_expires_at' => 'immutable_datetime',
            'part_no' => 'integer',
            'total_parts' => 'integer',
            'parent_job_id' => 'integer',
        ];
    }
}
