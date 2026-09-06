<?php

namespace App\Agents;

/**
 * خطأ متوقع في مسار مساعد بروماكس — رسالته آمنة تتعرض للمستخدم
 * (مفيش تفاصيل API ولا مفاتيح جواها أبداً).
 */
class AgentException extends \RuntimeException
{
}
