<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayslipLineCategory;
use App\Modules\Payroll\Domain\PayslipLineDraft;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserProvidedMetadataTest extends TestCase
{
    public function test_source_rejects_a_null_byte_laden_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayrollLineSource('scheme_item', "1\0");
    }

    public function test_source_rejects_a_null_byte_laden_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayrollLineSource("scheme\0item", '1');
    }

    public function test_line_rejects_a_category_info_with_negative_money(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayslipLineDraft(
            itemCode: 'INFO',
            category: PayslipLineCategory::INFO,
            amount: Money::fromMinor(-1),
            source: new PayrollLineSource('scheme_item', '1'),
            sortOrder: 1,
        );
    }

    public function test_money_rejects_a_malformed_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'CN');
    }
}
