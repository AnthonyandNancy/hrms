<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Published payslips and their lines are immutable. The guard is enforced at
 * the model layer, and SQLite/MySQL triggers provide the DB backstop.
 */
/**
 * @property int $id
 * @property int $run_id
 * @property int $employment_id
 * @property int $version
 * @property int $gross_cents
 * @property int $deductions_cents
 * @property int $net_cents
 * @property int $employer_cost_cents
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property int|null $pdf_file_id
 */
final class Payslip extends Model
{
    protected $table = 'payslips';

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'employment_id',
        'version',
        'gross_cents',
        'deductions_cents',
        'net_cents',
        'employer_cost_cents',
        'published_at',
        'pdf_file_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'gross_cents' => 'integer',
            'deductions_cents' => 'integer',
            'net_cents' => 'integer',
            'employer_cost_cents' => 'integer',
            'published_at' => 'immutable_datetime',
            'pdf_file_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<PayslipLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class, 'payslip_id')->orderBy('sort_order');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    protected static function booted(): void
    {
        self::updating(function (Payslip $payslip): void {
            if ($payslip->getOriginal('published_at') !== null) {
                throw new \DomainException('Published payslips are immutable.');
            }
        });

        self::deleting(function (Payslip $payslip): void {
            if ($payslip->published_at !== null) {
                throw new \DomainException('Published payslips are immutable.');
            }
        });
    }
}
