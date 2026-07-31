<?php

namespace App\Http\Controllers;

use App\Demo\DemoData;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard.index', [
            'kpis' => DemoData::kpis(),
            'salesWeek' => DemoData::salesWeek(),
            'productGroups' => DemoData::productGroups(),
            'requests' => DemoData::requests(),
            'invoices' => DemoData::invoices(),
            'tracking' => DemoData::tracking(),
        ]);
    }

    public function reps()
    {
        return view('dashboard.reps', [
            'reps' => DemoData::reps(),
            'tracking' => DemoData::tracking(),
        ]);
    }

    public function approvals()
    {
        return view('dashboard.approvals', [
            'requests' => DemoData::requests(),
        ]);
    }

    public function products()
    {
        $products = DemoData::products();

        return view('dashboard.products', [
            'products' => $products,
            'totalValue' => array_sum(array_column($products, 'value')),
            'totalStock' => array_sum(array_column($products, 'stock')),
        ]);
    }

    public function orders()
    {
        return view('dashboard.orders', [
            'invoices' => DemoData::invoices(),
            'pos' => DemoData::pos(),
        ]);
    }
}
