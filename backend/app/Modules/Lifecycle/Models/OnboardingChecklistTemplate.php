<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Models;

use Illuminate\Database\Eloquent\Model;

final class OnboardingChecklistTemplate extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'onboarding_checklist_templates';

    /** @var list<string> */
    protected $fillable = [
        'task_code',
        'title',
        'required',
        'assignee_role',
        'sort_order',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'sort_order' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
