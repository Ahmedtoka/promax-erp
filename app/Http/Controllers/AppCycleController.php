<?php

namespace App\Http\Controllers;

use App\Demo\DemoData;

/**
 * سايكل الموبايل أبلكيشن — داتا ديمو لحد ما نربط الأبلكيشن بالـ API
 */
class AppCycleController extends Controller
{
    public function cycle()
    {
        return view('appcycle.cycle', [
            'kpis' => DemoData::kpis(),
            'tracking' => DemoData::tracking(),
        ]);
    }

    public function reps()
    {
        return view('appcycle.reps', [
            'reps' => DemoData::reps(),
            'tracking' => DemoData::tracking(),
        ]);
    }

    public function approvals()
    {
        return view('appcycle.approvals', [
            'requests' => DemoData::requests(),
        ]);
    }

    public function orders()
    {
        return view('appcycle.orders', [
            'invoices' => DemoData::invoices(),
            'pos' => DemoData::pos(),
        ]);
    }
}
