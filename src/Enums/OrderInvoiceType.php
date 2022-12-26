<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static ElectricityInvoice()
 * @method static static Carrier()
 * @method static static UniformNumber()
 */
final class OrderInvoiceType extends Enum
{
    const ElectricityInvoice = 1;    // 代存電子發票
    const Carrier = 2;               // 手機條碼載具
    const UniformNumber = 3;         // 公司統編
}
