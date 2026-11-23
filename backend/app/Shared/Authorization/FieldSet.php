<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * The field projection that accompanies a resolved row scope. P2 values are
 * masked, P3 values are omitted entirely and never serialized.
 */
final readonly class FieldSet
{
    /**
     * @param  list<string>  $visible  Field names, or ['*'] for all P0/P1 fields.
     * @param  list<string>  $filterable
     * @param  list<string>  $exportable
     * @param  list<string>  $masked
     * @param  list<string>  $hidden
     */
    public function __construct(
        public array $visible,
        public array $filterable = [],
        public array $exportable = [],
        public array $masked = [],
        public array $hidden = [],
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function apply(array $row): array
    {
        foreach ($this->hidden as $field) {
            unset($row[$field]);
        }

        foreach ($this->masked as $field) {
            if (array_key_exists($field, $row) && is_string($row[$field]) && $row[$field] !== '') {
                $row[$field] = '***'.mb_substr($row[$field], -4);
            }
        }

        if (! in_array('*', $this->visible, true)) {
            $row = array_intersect_key($row, array_flip($this->visible));
        }

        return $row;
    }
}
