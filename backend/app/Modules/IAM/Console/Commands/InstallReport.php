<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

/**
 * Step-by-step result of one install run. A failed step is reported rather than
 * silently swallowed; already-created rows make the next run resume safely.
 */
final class InstallReport
{
    /** @var list<InstallStep> */
    private array $steps = [];

    public function record(string $name, string $status, string $detail = ''): void
    {
        $this->steps[] = new InstallStep($name, $status, $detail);
    }

    /**
     * @return list<InstallStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function failed(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->status === InstallStep::STATUS_FAILED) {
                return true;
            }
        }

        return false;
    }
}
