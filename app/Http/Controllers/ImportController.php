<?php

namespace App\Http\Controllers;

use App\Models\Import;
use App\Services\Importers\ClientImporter;
use App\Services\Importers\Importer;
use App\Services\Importers\ProductImporter;
use App\Services\Importers\StockImporter;
use App\Services\Importers\TeamImporter;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * استيراد الداتا — رفع، معاينة، تأكيد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ خطوتين مقصودتين: الرفع بيقرا ويتحقق ويعرض **من غير ما يكتب**،
 * والتأكيد هو اللي بيكتب. استيراد داتا تأسيسية بيغيّر أرقام كل
 * الشاشات، فلازم اليوزر يشوف الأول إيه اللي هيدخل وإيه اللي اترفض.
 *
 * ⚠️ الترتيب مهم: المنتجات ← العملاء ← الفريق ← المخزون. المخزون
 * بيشير للمنتجات، والعملاء بيشيروا للقنوات والزونز.
 */
class ImportController extends Controller
{
    /** الأنواع بترتيب التنفيذ المفروض */
    private const KINDS = [
        'products' => ProductImporter::class,
        'clients' => ClientImporter::class,
        'team' => TeamImporter::class,
        'stock' => StockImporter::class,
    ];

    public function index()
    {
        return view('erp.import', [
            'kinds' => array_keys(self::KINDS),
            'columns' => collect(self::KINDS)->map(fn ($c) => [
                'columns' => (new $c)->columns(),
                'required' => (new $c)->required(),
            ])->all(),
            'history' => Import::with('user')->latest()->take(30)->get(),
            'counts' => $this->counts(),
        ]);
    }

    /** رفع + معاينة — مابيكتبش حاجة في الداتا */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(self::KINDS))],
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
            'sheet' => ['nullable', 'string', 'max:190'],
        ]);

        $importer = $this->importer($data['kind']);

        // ⚠️ ممنوع ->store(): من Laravel 11 جذر ديسك local بقى
        // storage/app/**private**، فالملف بيتكتب في مكان والقراءة بتدوّر
        // في مكان تاني وكل رفع بيفشل برسالة مضلّلة. بنكتب بالمسار المباشر.
        $dir = storage_path('app/imports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = $request->file('file')->hashName();
        $request->file('file')->move($dir, $name);

        $stored = 'imports/'.$name;
        $path = $dir.DIRECTORY_SEPARATOR.$name;

        $sheet = ($data['sheet'] ?? null) ?: null;
        $read = $importer->read($path, 0, $sheet);

        if ($read['missing']) {
            return back()->withErrors(['file' => __('import.missing_columns', [
                'columns' => implode(', ', $read['missing']),
            ])]);
        }
        if ($read['errors']) {
            return back()->withErrors(['file' => implode(' · ', $read['errors'])]);
        }

        $checked = $importer->validateAll($read['rows']);

        $import = Import::create([
            'kind' => $data['kind'],
            'file_name' => $request->file('file')->getClientOriginalName(),
            'file_path' => $stored,
            'sheet' => $sheet,
            'status' => Import::STATUS_PENDING,
            'rows_total' => count($read['rows']),
            'rows_ok' => count($checked['ok']),
            'rows_failed' => count($read['rows']) - count($checked['ok']),
            // أول 60 خطأ بس — الباقي نفس النمط غالباً
            'errors' => array_slice($checked['errors'], 0, 60),
            'summary' => [
                'mapped' => $read['mapped'],
                'headers' => $read['headers'],
                'sheets' => $read['sheets'] ?? [],
                'header_row' => $read['header_row'] ?? 1,
            ],
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('erp.import.preview', $import);
    }

    public function preview(Import $import)
    {
        if ($import->file_path === null) {
            abort(404);
        }

        $importer = $this->importer($import->kind);
        $path = storage_path('app/'.$import->file_path);

        // أول 15 صف للعرض بس
        $read = is_file($path) ? $importer->read($path, 15, $import->sheet) : ['rows' => []];

        return view('erp.import_preview', [
            'import' => $import,
            'sample' => array_slice($read['rows'], 0, 15),
            'columns' => array_keys($importer->columns()),
        ]);
    }

    /** التنفيذ الفعلي */
    public function apply(Request $request, Import $import)
    {
        if (! $import->isPending()) {
            return back()->withErrors(['status' => __('import.already_applied')]);
        }

        $path = storage_path('app/'.$import->file_path);
        if (! is_file($path)) {
            return back()->withErrors(['status' => __('import.file_gone')]);
        }

        $importer = $this->importer($import->kind);
        $read = $importer->read($path, 0, $import->sheet);
        $checked = $importer->validateAll($read['rows']);

        if (! $checked['ok']) {
            return back()->withErrors(['status' => __('import.nothing_valid')]);
        }

        // ⚠️ قفل على صف الاستيراد: ضغطتين في نفس اللحظة كانوا يعدّوا
        // فحص isPending الاتنين ويستوردوا نفس الشيت مرتين.
        $locked = Import::whereKey($import->id)->lockForUpdate()->first();
        if ($locked === null || ! $locked->isPending()) {
            return back()->withErrors(['status' => __('import.already_applied')]);
        }

        try {
            $summary = $importer->apply($checked['ok']);
        } catch (\Throwable $e) {
            $import->update([
                'status' => Import::STATUS_FAILED,
                'errors' => array_slice(array_merge($checked['errors'], [$e->getMessage()]), 0, 60),
            ]);

            return back()->withErrors(['status' => __('import.failed', ['error' => $e->getMessage()])]);
        }

        // ملاحظات التنفيذ (تخطي القرب الجغرافي / الجيوكودينج) —
        // بتتعرض مع الأخطاء عشان تبان في كارت الاستيراد
        $notes = method_exists($importer, 'notes') ? $importer->notes() : [];

        $import->update([
            'status' => Import::STATUS_APPLIED,
            'rows_ok' => count($checked['ok']),
            'rows_failed' => count($read['rows']) - count($checked['ok']),
            'errors' => array_slice(array_merge($checked['errors'], $notes), 0, 60),
            'summary' => array_merge($import->summary ?? [], ['result' => $summary]),
            'applied_at' => now(),
        ]);

        return redirect()->route('erp.import')->with('ok', __('import.applied', [
            'rows' => count($checked['ok']),
            'kind' => __('import.kind_'.$import->kind),
        ]));
    }

    /** قالب فاضي بالأعمدة المتوقّعة */
    public function template(string $kind)
    {
        abort_unless(array_key_exists($kind, self::KINDS), 404);

        return response($this->importer($kind)->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="promax-'.$kind.'-template.csv"',
        ]);
    }

    private function importer(string $kind): Importer
    {
        $class = self::KINDS[$kind];

        return new $class;
    }

    /** الموجود دلوقتي — عشان اليوزر يعرف هو فين */
    private function counts(): array
    {
        return [
            'products' => \App\Models\Product::count(),
            'clients' => \App\Models\Client::count(),
            'team' => \App\Models\User::count(),
            'stock' => \App\Models\Batch::count(),
        ];
    }
}
