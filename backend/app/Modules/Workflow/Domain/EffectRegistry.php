<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

use App\Modules\Workflow\Contracts\EffectHandler;
use App\Shared\Exceptions\ValidationException;

/**
 * Effect type resolution is strict: unknown types are rejected before a
 * decision is committed, not after an asynchronous worker discovers them.
 */
final class EffectRegistry
{
    /** @var list<EffectHandler> */
    private array $handlers;

    /** @var list<string> */
    private array $types;

    /**
     * @param  list<EffectHandler>  $handlers
     * @param  list<string>|null  $supportedTypes  Explicit catalog used by production
     *                                             wiring; tests may resolve by supports().
     */
    public function __construct(array $handlers, ?array $supportedTypes = null)
    {
        $seenHandlers = [];
        $this->handlers = [];

        foreach ($handlers as $handler) {
            $objectId = spl_object_id($handler);

            if (isset($seenHandlers[$objectId])) {
                throw new \LogicException('The same effect handler was registered twice.');
            }

            $seenHandlers[$objectId] = true;
            $this->handlers[] = $handler;
        }

        if ($supportedTypes !== null) {
            $types = array_values(array_unique($supportedTypes));
            $seenTypes = [];

            foreach ($types as $type) {
                if (isset($seenTypes[$type])) {
                    throw new \LogicException("Effect type {$type} is registered twice.");
                }

                $seenTypes[$type] = true;
                $matching = array_values(array_filter(
                    $this->handlers,
                    static fn (EffectHandler $handler): bool => $handler->supports($type),
                ));

                if (count($matching) !== 1) {
                    throw new \LogicException("Effect type {$type} must have exactly one handler.");
                }
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

        foreach ($this->handlers as $handler) {
            if ($handler->supports($effectType)) {
                return true;
            }
        }

        return false;
    }

    public function resolve(string $effectType): EffectHandler
    {
        $matches = array_values(array_filter(
            $this->handlers,
            static fn (EffectHandler $handler): bool => $handler->supports($effectType),
        ));

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
