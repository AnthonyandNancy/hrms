<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Employee certificate/qualification.
 */
final class EmployeeCertificate extends Model
{
    protected $table = 'employee_certificates';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'certificate_type',
        'certificate_no',
        'issuer',
        'issued_on',
        'expires_on',
        'document_id',
        'recorded_at',
        'superseded_at',
        'created_at',
        'updated_at',
    ];
}
