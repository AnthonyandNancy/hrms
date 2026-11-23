<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use Illuminate\Database\Eloquent\Model;

final readonly class ResourceRef
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $type,
        public int|string $id,
        public array $values = [],
    ) {}

    public static function fromModel(Model $model): self
    {
        return new self($model->getTable(), (int) $model->getKey(), $model->attributesToArray());
    }

    public function value(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }
}
