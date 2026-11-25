<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Typed runtime parameter. JSON storage keeps the reader generic while the
 * contract exposes a typed default so callers never branch on missing values.
 */
final class SystemParameter extends Model
{
    protected $table = 'sys_parameters';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'key', 'value_json', 'scope', 'sensitive'];

    /**
     * A sensitive parameter is stored with Laravel's authenticated encryption;
     * everything else stays plain JSON. `value_json` is therefore a string at
     * rest for sensitive rows and an array for plain rows, which is why the
     * reader never relies on the raw attribute cast.
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'value_json' => 'json',
            'sensitive' => 'boolean',
        ];
    }

    /**
     * Decrypt-on-read for sensitive rows, plain JSON decode otherwise.
     *
     * @return array<string, mixed>
     */
    public function decodedValue(): array
    {
        $raw = $this->getAttribute('value_json');

        if (is_array($raw)) {
            return $raw;
        }

        $payload = is_string($raw) ? $raw : '';

        if ($this->sensitive) {
            $payload = (string) \Illuminate\Support\Facades\Crypt::decryptString($payload);
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
