<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

use App\Modules\Workflow\Contracts\EffectHandler;
use App\Shared\Exceptions\ValidationException;
use Closure;

/**
 * Effect type resolution is strict: unknown types are rejected before a
 * decision is committed, not after an asynchronous worker discovers them.
 *
 * Handlers are held as factories and resolved only when an effect actually
 * needs them. This is required for modular monolith wiring: Workflow is
 * constructed by modules that themselves own domain effect handlers, so
 * resolving those handlers during Workflow's construction would create a
 * container cycle.
 */
final class EffectRegistry
{
    /** @var list<Closure(): EffectHandler> */
    private array $factories;

    /** @var array<string, EffectHandler> */
    private array $resolved = [];

    /** @var list<string> */
    private array $types;

    /**
     * @param  list<EffectHandler|Closure(): EffectHandler>  $handlers
     * @param  list<string>|null  $supportedTypes
     */
    public function __construct(array $handlers, ?array $supportedTypes = null)
    {
        $this->factories = [];

        foreach ($handlers as $handler) {
            if ($handler instanceof EffectHandler) {
                $objectId = spl_object_id($handler);
                if (isset($this->resolved[$objectId])) {
                    throw new \LogicException('An effect handler instance is registered twice.');
                }
                $this->resolved[$objectId] = $handler;
                $this->factories[] = static fn (): EffectHandler => $handler;

                continue;
            }

            if ($handler instanceof Closure) {
                $this->factories[] = $handler;

                continue;
            }

            throw new \LogicException('Effect handlers must be instances or factories.');
        }

        if ($supportedTypes !== null) {
            $types = array_values(array_unique($supportedTypes));
            $seenTypes = [];

            foreach ($types as $type) {
                if (isset($seenTypes[$type])) {
                    throw new \LogicException("Effect type {$type} is registered twice.");
                }

                $seenTypes[$type] = true;
            }

            $this->types = $types;
        } else {
            $this->types = [];
        }
    }

    public function has(string $effectType): bool
    {
        if (in_array($effectType, $this->types, true)) {
            return true;
        }

        foreach ($this->factories as $factory) {
            if ($factory()->supports($effectType)) {
                return true;
            }
        }

        return false;
    }

    public function resolve(string $effectType): EffectHandler
    {
        $matches = [];

        foreach ($this->factories as $factory) {
            $handler = $factory();

            if ($handler->supports($effectType)) {
                $matches[] = $handler;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            throw new \LogicException("Multiple effect handlers support {$effectType}.");
        }

        throw (new ValidationException(
            ['effect_type' => ['Unknown effect type.']],
            'Unknown effect type.',
        ))->withReason('UNKNOWN_EFFECT_TYPE');
    }

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return $this->types;
    }
}
