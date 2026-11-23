<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Effect handler whose signature carries only Workflow contract DTOs. Domain
 * modules implement this instead of the legacy model-coupled EffectHandler.
 */
interface DomainEffectHandler
{
    public function supports(string $effectType): bool;

    public function apply(DomainEffectCommand $command): DomainEffectResult;

    public function reverse(DomainEffectCommand $command): DomainEffectResult;
}
