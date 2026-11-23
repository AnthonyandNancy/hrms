<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Domain;

enum LifecycleCaseType: string
{
    case ONBOARDING = 'ONBOARDING';
    case PROBATION_CONFIRM = 'PROBATION_CONFIRM';
    case ASSIGNMENT_CHANGE = 'ASSIGNMENT_CHANGE';
    case TERMS_CHANGE = 'TERMS_CHANGE';
    case OFFBOARD = 'OFFBOARD';
    case CONTRACT_RENEW = 'CONTRACT_RENEW';
    case REHIRE = 'REHIRE';

    public function effectType(): string
    {
        return 'lifecycle.'.strtolower($this->value);
    }

    public function templateCode(): string
    {
        return 'lifecycle.'.strtolower($this->value);
    }
}
