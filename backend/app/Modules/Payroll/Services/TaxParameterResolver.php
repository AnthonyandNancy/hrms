<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Domain\TaxBracket as TaxBracketDto;
use App\Modules\Payroll\Domain\TaxTable;
use App\Modules\Payroll\Enums\PayrollParameterSet;
use App\Modules\Payroll\Models\StatutoryParameter;
use App\Modules\Payroll\Models\TaxBracket;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Money\Percentage;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the approved, effective parameter versions for the company.
 * There is deliberately no default statutory rate in this class.
 */
final class TaxParameterResolver
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * Return the version label effective on the given instant.
     * Parameters effective on that business date are selected; an
     * unconfigured set fails closed rather than inventing rates.
     */
    public function versionFor(string $parameterSet, string $on): string
    {
        $date = substr($on, 0, 10);
        $row = StatutoryParameter::query()
            ->where('parameter_set', $parameterSet)
            ->where('effective_from', '<=', $date)
            ->where('effective_to', '>', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            throw new ConflictException("No approved {$parameterSet} parameters are effective for the company.", [
                'reason' => 'STATUTORY_PARAMETERS_MISSING',
                'parameter_set' => $parameterSet,
                'on' => $date,
            ]);
        }

        return (string) $row->version;
    }

    public function taxTable(string $on): TaxTable
    {
        $date = substr($on, 0, 10);
        $threshold = $this->parameter(PayrollParameterSet::TAX, 'THRESHOLD_CENTS', $date);
        $version = (string) $threshold->version;

        $setRows = StatutoryParameter::query()
            ->where('parameter_set', PayrollParameterSet::TAX->value)
            ->where('version', $version)
            ->where('effective_from', '<=', $date)
            ->where('effective_to', '>', $date)
            ->orderBy('id')
            ->get();

        $bracketRows = TaxBracket::query()
            ->whereIn('parameter_set_id', $setRows->pluck('id')->all())
            ->orderBy('lower_bound_cents')
            ->get();

        if ($bracketRows->isEmpty()) {
            throw new ConflictException('The selected tax parameter version has no brackets.', [
                'reason' => 'TAX_BRACKETS_MISSING',
                'parameter_version' => $version,
            ]);
        }

        $brackets = $bracketRows->map(static fn (TaxBracket $bracket): TaxBracketDto => new TaxBracketDto(
            lowerCents: (int) $bracket->lower_bound_cents,
            upperCents: $bracket->upper_bound_cents !== null ? (int) $bracket->upper_bound_cents : null,
            rate: Percentage::fromScaledInt((int) $bracket->rate_bps * 10000),
            quickDeductionCents: (int) $bracket->quick_deduction_cents,
        ))->values()->all();

        return new TaxTable(
            version: $version,
            thresholdCents: (int) $threshold->value_int,
            brackets: $brackets,
        );
    }

    /**
     * @return array{version: string, employee_rate: Percentage, employer_rate: Percentage, min_base_cents: int, max_base_cents: int}
     */
    public function insuranceRule(string $set, string $on): array
    {
        $date = substr($on, 0, 10);
        $parameterSet = PayrollParameterSet::from($set);
        $min = $this->parameter($parameterSet, 'MIN_BASE_CENTS', $date);
        $max = $this->parameter($parameterSet, 'MAX_BASE_CENTS', $date);
        $employee = $this->parameter($parameterSet, 'EMPLOYEE_RATE', $date);
        $employer = $this->parameter($parameterSet, 'EMPLOYER_RATE', $date);
        $version = (string) $min->version;

        foreach ([$max, $employee, $employer] as $row) {
            if ((string) $row->version !== $version) {
                throw new ConflictException('The effective statutory parameter rows do not share one version.', [
                    'reason' => 'STATUTORY_PARAMETER_VERSION_MIXED',
                    'parameter_set' => $parameterSet->value,
                ]);
            }
        }

        if ((int) $min->value_int > (int) $max->value_int) {
            throw new ValidationException(['statutory_parameters' => ['The minimum base must not exceed the maximum base.']]);
        }

        return [
            'version' => $version,
            'employee_rate' => Percentage::fromPercent((string) $employee->value_decimal),
            'employer_rate' => Percentage::fromPercent((string) $employer->value_decimal),
            'min_base_cents' => (int) $min->value_int,
            'max_base_cents' => (int) $max->value_int,
        ];
    }

    private function parameter(PayrollParameterSet $set, string $code, string $date): StatutoryParameter
    {
        $row = StatutoryParameter::query()
            ->where('parameter_set', $set->value)
            ->where('code', $code)
            ->where('effective_from', '<=', $date)
            ->where('effective_to', '>', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            throw new ConflictException("No approved {$set->value} parameter {$code} is effective for the company.", [
                'reason' => 'STATUTORY_PARAMETER_MISSING',
                'parameter_set' => $set->value,
                'code' => $code,
                'on' => $date,
            ]);
        }

        return $row;
    }

    /**
     * Persist a complete, approved parameter version and its brackets. Input
     * rates are decimal strings; bps conversion is exact (percentage * 100).
     *
     * @param  array<string, mixed>  $data
     * @return list<StatutoryParameter>
     */
    public function createVersion(array $data, int $approvedBy, bool $isDemo = false, ?string $evidenceRef = null): array
    {
        $set = PayrollParameterSet::from((string) $data['parameter_set']);
        $version = (string) $data['version'];
        $from = BusinessDate::from((string) $data['effective_from'])->value();
        $to = BusinessDate::from((string) ($data['effective_to'] ?? BusinessDate::OPEN_END))->value();
        $evidence = $evidenceRef ?? (string) ($data['evidence_ref'] ?? '');

        if (trim($evidence) === '') {
            throw new ValidationException(['evidence_ref' => ['Statutory parameter versions require approval evidence.']]);
        }

        return DB::transaction(function () use ($set, $version, $from, $to, $data, $approvedBy, $isDemo, $evidence): array {
            $rows = [];
            $now = $this->clock->now();

            foreach ($this->requiredCodes($set) as $code) {
                $value = $data[$code] ?? null;

                if ($value === null) {
                    throw new ValidationException([$code => ["The {$code} value is required for {$set->value}."]]);
                }

                $row = StatutoryParameter::query()->create([
                    'parameter_set' => $set->value,
                    'code' => $code,
                    'value_decimal' => is_string($value) ? $value : null,
                    'value_int' => is_int($value) ? $value : (is_numeric($value) && str_contains((string) $value, '.') === false ? (int) $value : null),
                    'effective_from' => $from,
                    'effective_to' => $to,
                    'version' => $version,
                    'approved_by' => $approvedBy,
                    'approved_at' => $now,
                    'evidence_ref' => $evidence,
                    'is_demo' => $isDemo,
                ]);

                $rows[] = $row;
            }

            if ($set === PayrollParameterSet::TAX) {
                $brackets = (array) ($data['brackets'] ?? []);

                if ($brackets === []) {
                    throw new ValidationException(['brackets' => ['Tax parameter versions require brackets.']]);
                }

                foreach ($brackets as $bracket) {
                    // Keep relationships explicit: the THRESHOLD row carries
                    // the set version; brackets reference it.
                    TaxBracket::query()->create([
                        'parameter_set_id' => (int) $rows[0]->id,
                        'lower_bound_cents' => (int) $bracket['lower_bound_cents'],
                        'upper_bound_cents' => $bracket['upper_bound_cents'] !== null ? (int) $bracket['upper_bound_cents'] : null,
                        'rate_bps' => (int) $bracket['rate_bps'],
                        'quick_deduction_cents' => (int) ($bracket['quick_deduction_cents'] ?? 0),
                        'created_at' => $now,
                    ]);
                }
            }

            return $rows;
        });
    }

    /**
     * @return list<string>
     */
    private function requiredCodes(PayrollParameterSet $set): array
    {
        return match ($set) {
            PayrollParameterSet::TAX => ['THRESHOLD_CENTS'],
            PayrollParameterSet::SOCIAL_INSURANCE, PayrollParameterSet::HOUSING_FUND => ['MIN_BASE_CENTS', 'MAX_BASE_CENTS', 'EMPLOYEE_RATE', 'EMPLOYER_RATE'],
            PayrollParameterSet::ROUNDING => ['MODE'],
        };
    }
}
