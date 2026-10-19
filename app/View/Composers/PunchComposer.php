<?php

namespace App\View\Composers;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Services\Attendance;
use App\Support\Access;
use Illuminate\View\View;

/**
 * زرار البانش في هيدر الداش بورد (Lane 7 — المهمة ٢).
 *
 * ⚠️ **قراءة بس** — مفيش `firstOrCreate` على عرض الصفحة (نفس قاعدة
 * `peekStates`): يوم مالوش صف = `off`. كويري للموظف وكويري لليوم بانشاته.
 */
class PunchComposer
{
    public function compose(View $view): void
    {
        $view->with('punchBar', $this->bar());
    }

    /** @return array{state: string, next: list<string>}|null */
    private function bar(): ?array
    {
        $user = auth()->user();

        if ($user === null || ! Access::allows($user, 'attendance.punch')
            || Employee::forUser($user) === null) {
            return null;
        }

        $day = AttendanceDay::with('punches')
            ->where('user_id', $user->id)
            ->where('date', today()->toDateString())
            ->first();

        $state = $day?->state() ?? 'off';

        return ['state' => $state, 'next' => Attendance::nextTypes($state)];
    }
}
