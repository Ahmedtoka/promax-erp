<?php

namespace App\Contracts\Gl;

/**
 * سجل الأصول الثابتة — الخط ٤ بيربطه (تكلفة الأصل مدين / مجمع الإهلاك دائن
 * لكل حساب أصل). الديفولت `NullFixedAssetRegister` بيرجّع ولا سطر.
 */
interface FixedAssetRegister extends OpeningProvider
{
}
