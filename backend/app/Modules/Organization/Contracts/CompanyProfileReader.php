<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

interface CompanyProfileReader
{
    public function current(): CompanyProfileInfo;
}
