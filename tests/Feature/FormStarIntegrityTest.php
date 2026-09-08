<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * النجمة على الشاشة = قاعدة السيرفر — في **كل** فورم
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الملف ده اتكتب بعد مودال الاعتماد (٨ سبتمبر ٢٠٢٦).**
 * `price_list_id` كانت `required_if:decision,approved` (بلاغ INV-1065)
 * والخانة من غير نجمة — المعتمِد ملا الفورم ودوس «اعتماد» ورجع برسالة
 * خطأ من غير ما حاجة على الشاشة تقول إن الخانة دي لازمة.
 *
 * `ClientFormIntegrityTest::test_the_star_on_screen_matches_the_server_rules`
 * كان بيفحص الاتجاه ده لفورم العميل **بس**، وفي اتجاه واحد بس (النجمة
 * → السيرفر). هنا الاتجاهين، على كل فورم في كل فيو:
 *
 *   • خانة معلَّمة إجبارية (`required` · `data-req` · `<b class="req-star">`
 *     · `*` آخر الليبل) والسيرفر بيقبلها فاضية = **كدب في الواجهة**.
 *   • خانة السيرفر بيطلبها (`required` أو `required_if/…`) ومن غير
 *     علامة = **المستخدم بيرجع بأخطاء مش فاهم ليه** — ده اللي حصل.
 *
 * ⚠️ **بيقرا الـBlade والكنترولر كنص عن قصد** — نفس منطق
 * `ClientFormIntegrityTest::formFieldNames()`: الرندر محتاج داتا وبيخبّي
 * الخانات اللي جوه شرط، والخانة المخبية هي اللي بتتنسى.
 */
class FormStarIntegrityTest extends TestCase
{
    /**
     * فورمات الـ`action` بتاعها بتتكتب من الجافاسكربت وقت الفتح
     * (`document.getElementById('x').action = …`) — فمفيش `route()` في
     * التاج نقرأه. الخريطة دي بتقول كل فورم بيروح لأنهي راوت.
     *
     * ⚠️ **الفورم اللي بيخدم راوتين** (اعتماد/مراجعة) بيتقاس على
     * الاتنين: النجمة ناقصة لو **أي واحد** طالبها، وكدب لو **ولا واحد**
     * طالبها. الجافاسكربت هو اللي بيختار الراوت، والمستخدم لازم يعرف.
     *
     * @var array<string, list<string>>
     */
    private const DYNAMIC_FORMS = [
        'erp/account_audit#auditForm' => ['erp.audit.save'],
        'erp/branches#formEditBranch' => ['erp.branches.update'],
        'erp/channels#formCh' => ['erp.channels.update'],
        'erp/channels#formMgr' => ['erp.channels.manager'],
        'erp/client_locations#geoForm' => ['erp.client_locations.confirm'],
        'erp/leads#formEditLead' => ['erp.leads.update'],
        'erp/replenishments#formRpl' => ['ops.replenishments.assign'],
        'erp/replenishments#formRplEdit' => ['ops.replenishments.update'],
        'erp/team#passForm' => ['erp.team.password'],
        'erp/vehicles#formEditVehicle' => ['erp.vehicles.update'],
        'online/collections#formCollect' => ['online.collect'],
        'online/pickup#formCollect' => ['online.collect'],
        'online/pickup#formCancel' => ['online.cancel'],
        'online/prep#ppDoneForm' => ['online.prep.done'],
        'online/prep#ppReviewForm' => ['online.prep.review'],
        'online/sync#formPostpone' => ['online.postpone'],
        'online/sync#formCancel' => ['online.cancel'],
        'online/sync#formItemLink' => ['online.item.link'],
        'ops/pos#formAssign' => ['ops.pos.assign'],
        'ops/po_approvals#poRejectForm' => ['ops.po.decide'],
        'ops/requests#formDecide' => ['ops.requests.decide', 'ops.requests.revise'],
    ];

    /**
     * فروق مقصودة وموثّقة — كل سطر سببه معاه. الجديد مش بيتضاف هنا غير
     * لما يكون الفرق قرار، مش سهو.
     *
     * المفتاح: `الفيو#id-الفورم:الخانة` أو `الفيو:الخانة` (كل فورمات الفيو).
     *
     * @var array<string, string>
     */
    private const DELIBERATE = [
        // الجافاسكربت بيطلبهم للعميل الجديد، والقواعد سايباهم nullable
        // عشان الـ103 عميل القدام اتعملوا قبل القاعدة (موثّق في
        // ClientFormIntegrityTest::test_the_star_on_screen_matches_the_server_rules)
        'erp/client_form:name_en' => 'legacy clients predate the rule',
        'erp/client_form:channel_id' => 'legacy clients predate the rule',
        // فورم الصف في موافقات أوامر التوريد فيه زرارين (اعتماد/رفض) —
        // الملاحظة `required_if:decision,rejected`، و`poCheckNote()` بيوقف
        // الرفض من غيرها، ومودال الرفض المنفصل شايل النجمة. نجمة ثابتة
        // على خانة الاعتماد كانت هتقول إنها لازمة للاعتماد وهي مش لازمة.
        'ops/po_approvals#poForm:note' => 'required only for the reject button; poCheckNote() guards it and the reject modal carries the star',
        // كمية التجهيز لكل صف: الفاضي معناه «زي المطلوب»
        // (`PickOrder::markReady` → `?? $item->qty_requested`). الـ`required`
        // في المتصفح بيمنع أمين المخزن يعدّي صف بالغلط، والسيرفر بيكمّل
        // الناقص بالمطلوب عن قصد — مش كدب، تعمُّد.
        'wh/pick:picked.*' => 'empty means "as requested"; the browser guard stops a row being skipped by accident',
        // فرع الـ`@empty` في فورم الصنف — داتابيز لسه ماتهاجرتش للقوايم،
        // فالعمودان القديمان هما السعر الوحيد والنجمة عليهم صح. القاعدة
        // سايباهم nullable عن قصد (قرار 2026-08-04): المستورد بيبعت
        // `list_price[...]` والعمودان فولباك بس.
        'erp/_product_form:price_old' => 'fallback branch when no price lists exist; columns stay nullable for the importer (2026-08-04)',
        'erp/_product_form:price_new' => 'fallback branch when no price lists exist; columns stay nullable for the importer (2026-08-04)',
    ];

    // ═══════════════════ 1. الخريطة نفسها ═══════════════════

    /**
     * كل فورم POST فيه خانات ومالوش `route()` في الـaction لازم يكون في
     * الخريطة — وإلا بيهرب من الفحص في صمت.
     */
    public function test_every_dynamic_form_is_mapped_to_its_route(): void
    {
        $unmapped = [];

        foreach ($this->forms() as $form) {
            if ($form['routes'] !== null || $form['fields'] === []) {
                continue;
            }

            $unmapped[] = $form['key'].' ('.count($form['fields']).' fields)';
        }

        $this->assertSame([], $unmapped,
            'فورمات بتتبعت من الجافاسكربت ومش في DYNAMIC_FORMS — مش بتتفحص: '.implode(', ', $unmapped));
    }

    /**
     * كل راوت في الخريطة موجود فعلاً — والفورم اللي فيه خانات بيوصل
     * لكنترولر نقدر نقرا قواعده.
     *
     * ⚠️ الفورم اللي كله زراير (`ppDoneForm`) مالوش قواعد ولا نجوم،
     * وده طبيعي. اللي فيه خانات ومفيش `validate(` ورا راوته، الخانات
     * دي بتوصل للداتابيز من غير أي فحص — وده بيتفحص هنا كمان.
     */
    public function test_every_mapped_route_resolves_to_readable_rules(): void
    {
        $bad = [];
        $withFields = [];

        foreach ($this->forms() as $form) {
            if ($form['fields'] !== []) {
                $withFields[$form['key']] = true;
            }
        }

        foreach (self::DYNAMIC_FORMS as $form => $routes) {
            foreach ($routes as $name) {
                if (Route::getRoutes()->getByName($name) === null) {
                    $bad[] = $form.' → '.$name.' (no such route)';

                    continue;
                }

                if (isset($withFields[$form]) && $this->rulesFor($name) === null) {
                    $bad[] = $form.' → '.$name.' (fields without readable rules)';
                }
            }
        }

        $this->assertSame([], $bad, implode(', ', $bad));
    }

    // ═══════════════════ 2. الفحص نفسه ═══════════════════

    /**
     * الخانة اللي الشاشة بتقول عليها إجبارية، السيرفر لازم يرفضها فاضية.
     *
     * ⚠️ نجمة على خانة السيرفر بيقبلها فاضية = الحماية في المتصفح بس،
     * وأي حفظ من الـAPI أو بجافاسكربت موقوفة بيعدّي.
     */
    public function test_a_starred_field_is_required_by_the_server(): void
    {
        $lies = [];

        foreach ($this->comparableForms() as $form) {
            foreach ($form['fields'] as $field) {
                if (! $field['marked'] || $this->deliberate($form, $field['key'])) {
                    continue;
                }

                $statuses = $this->statusesAcross($form['routes'], $field['key'], true);

                // كدب لو **ولا راوت** بيطلبها بأي شكل — `paired` مش كدب:
                // النجمة على «أساس الأيام» لما الأيام مكتوبة صح.
                if ($statuses !== [] && $statuses === array_fill(0, count($statuses), 'optional')) {
                    $lies[] = $form['key'].':'.$field['name'].' ['.implode('/', $statuses).']';
                }
            }
        }

        $this->assertSame([], $lies,
            "خانات معلَّمة إجبارية على الشاشة والسيرفر بيقبلها فاضية:\n  ".implode("\n  ", $lies));
    }

    /**
     * الخانة اللي السيرفر بيطلبها لازم يكون عليها علامة.
     *
     * ⚠️ ده بالظبط بلاغ مودال الاعتماد: `required_if` من غير نجمة.
     * السيلكت اللي أول اختيار فيه قيمة حقيقية، والخانة اللي عليها قيمة
     * افتراضية، مستثنيين — دول مستحيل يوصلوا فاضيين.
     */
    public function test_a_required_field_carries_a_star(): void
    {
        $missing = [];

        foreach ($this->comparableForms() as $form) {
            foreach ($form['fields'] as $field) {
                if ($field['marked'] || $field['prefilled'] || $this->deliberate($form, $field['key'])) {
                    continue;
                }

                $statuses = $this->statusesAcross($form['routes'], $field['key'], $field['inherits']);

                if ($statuses === []) {
                    continue;
                }

                // ⚠️ **أي راوت** بيطلبها يكفي — مش كلهم. مودال الاعتماد
                // بيخدم «اعتماد» (`required_if`) و«مراجعة» (`nullable`)؛
                // اشتراط الاتنين كان بيخبّي بالظبط بلاغ INV-1065 اللي
                // الملف ده اتكتب عشانه (اتأكدنا بشيل النجمة وتشغيل التيست).
                $anyRequired = count(array_filter(
                    $statuses,
                    fn ($s) => in_array($s, ['required', 'conditional'], true)
                )) > 0;

                if ($anyRequired) {
                    $missing[] = $form['key'].':'.$field['name'].' ['.implode('/', $statuses).']';
                }
            }
        }

        $this->assertSame([], $missing,
            "خانات السيرفر بيطلبها ومن غير نجمة على الشاشة:\n  ".implode("\n  ", $missing));
    }

    // ═══════════════════ الفورمات ═══════════════════

    /**
     * الفورمات اللي عرفنا راوتها وقدرنا نقرا قواعده.
     *
     * @return list<array<string, mixed>>
     */
    private function comparableForms(): array
    {
        $out = [];

        foreach ($this->forms() as $form) {
            if ($form['routes'] === null || $form['fields'] === []) {
                continue;
            }

            $readable = array_values(array_filter(
                $form['routes'],
                fn ($r) => $this->rulesFor($r) !== null
            ));

            if ($readable === []) {
                continue;
            }

            $form['routes'] = $readable;
            $out[] = $form;
        }

        return $out;
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $formsCache = null;

    /**
     * كل فورم POST في كل فيو: راوته وخاناته وعلاماتها.
     *
     * @return list<array<string, mixed>>
     */
    private function forms(): array
    {
        if ($this->formsCache !== null) {
            return $this->formsCache;
        }

        $out = [];
        $root = resource_path('views');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $view = str_replace(['\\', '.blade.php'], ['/', ''], substr($file->getPathname(), strlen($root) + 1));
            $src = $this->stripBladeComments((string) file_get_contents($file->getPathname()));

            preg_match_all('/<form\b((?:\{\{.*?\}\}|[^>])*)>(.*?)<\/form>/s', $src, $m, PREG_SET_ORDER);

            foreach ($m as $i => [, $tagAttrs, $body]) {
                $attrs = $this->attrs($tagAttrs);
                $method = strtoupper($attrs['method'] ?? 'GET');

                if ($method === 'GET') {
                    continue;   // فورمات الفلاتر — مالهاش قواعد ولا نجوم
                }

                // `id="poForm{{ $po->id }}"` ⇐ `poForm` — الجزء الثابت بس
                $id = isset($attrs['id']) ? trim((string) preg_replace('/\{\{.*?\}\}/s', '', $attrs['id'])) : null;
                $id = $id === '' ? null : $id;
                $key = $view.'#'.($id ?? 'form'.($i + 1));

                $routes = null;

                if (preg_match("/route\\('([A-Za-z0-9_.]+)'/", $attrs['action'] ?? '', $rm)) {
                    $routes = [$rm[1]];
                } elseif ($id !== null && isset(self::DYNAMIC_FORMS[$view.'#'.$id])) {
                    $routes = self::DYNAMIC_FORMS[$view.'#'.$id];
                }

                $out[] = [
                    'key' => $key,
                    'view' => $view,
                    'id' => $id,
                    'routes' => $routes,
                    'fields' => $this->fields($this->inlinePartials($body, $root)),
                ];
            }
        }

        return $this->formsCache = $out;
    }

    /**
     * `@include('erp._product_form')` جوه الفورم — الخانات في البارشال.
     */
    private function inlinePartials(string $body, string $root, int $depth = 0): string
    {
        if ($depth > 3) {
            return $body;
        }

        return (string) preg_replace_callback(
            "/@include\\('([A-Za-z0-9_.]+)'[^)]*\\)/",
            function (array $m) use ($root, $depth) {
                $path = $root.'/'.str_replace('.', '/', $m[1]).'.blade.php';

                if (! is_file($path)) {
                    return '';
                }

                return $this->inlinePartials(
                    $this->stripBladeComments((string) file_get_contents($path)), $root, $depth + 1
                );
            },
            $body,
        );
    }

    /**
     * خانات الفورم بالترتيب، وكل واحدة: معلَّمة؟ متعبّية أصلاً؟
     *
     * العلامة إما على التاج نفسه (`required` · `data-req`) أو في الليبل
     * اللي قبله مباشرة (`req-star` · `*` قبل قفلة تاج).
     *
     * @return list<array{name:string,key:string,marked:bool,prefilled:bool}>
     */
    private function fields(string $body): array
    {
        preg_match_all('/<(input|select|textarea)\b((?:\{\{.*?\}\}|[^>])*)>/s', $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $out = [];
        $cursor = 0;

        foreach ($m as $match) {
            $tagStart = $match[0][1];
            $tagEnd = $tagStart + strlen($match[0][0]);
            $kind = $match[1][0];
            $attrs = $this->attrs($match[2][0]);

            $chunk = substr($body, $cursor, $tagStart - $cursor);
            $cursor = $tagEnd;

            $name = $attrs['name'] ?? null;

            // ⚠️ اسم الخانة الحقيقي مافيهوش كوت ولا `+` — الريجيكس بيمسك
            // `querySelector('[name="…"]')` كمان (نفس ملاحظة الفورم القديم)
            if ($name === null || preg_match('/^[A-Za-z_][A-Za-z0-9_.* -]*(\[[^\]]*\])*$/', $name) !== 1) {
                continue;
            }

            $type = strtolower($attrs['type'] ?? ($kind === 'input' ? 'text' : $kind));

            if (in_array($type, ['hidden', 'checkbox', 'radio', 'submit', 'button', 'reset', 'image'], true)) {
                continue;
            }

            // الأوبشنز بتاعة السيلكت اللي قبله مش ليبل للخانة دي
            $chunk = (string) preg_replace('/<option\b.*?<\/option>/s', '', $chunk);
            $chunk = (string) preg_replace('/<script\b.*?<\/script>/s', '', $chunk);

            // ⚠️ `data-req-contract` وأخواتها علامات **شرطية**: الجافاسكربت
            // بيقلبها إجبارية لما الشرط يتحقق — نفس معنى `required_if`.
            $conditionalMarker = count(array_filter(
                array_keys($attrs),
                fn ($k) => str_starts_with($k, 'data-req-')
            )) > 0;

            $marked = array_key_exists('required', $attrs)
                || array_key_exists('data-req', $attrs)
                || $conditionalMarker
                || str_contains($chunk, 'req-star')
                || preg_match('/\*\s*<\//', $chunk) === 1;

            $prefilled = false;

            if ($kind === 'select') {
                $selectBody = substr($body, $tagEnd, (strpos($body, '</select>', $tagEnd) ?: $tagEnd) - $tagEnd);

                // أول اختيار بقيمة حقيقية = السيلكت عمره ما بيوصل فاضي
                if (preg_match('/<option\b((?:\{\{.*?\}\}|[^>])*)>/s', $selectBody, $om)) {
                    $oa = $this->attrs($om[1]);
                    $prefilled = array_key_exists('value', $oa) ? trim($oa['value']) !== '' : true;
                }
            } elseif (isset($attrs['value']) && trim($attrs['value']) !== '') {
                // `value="{{ old('x') }}"` فاضية عند الإنشاء — بس
                // `old('x', today())` أو قيمة حرفية معناها الخانة متعبّية
                $prefilled = ! str_contains($attrs['value'], 'old(')
                    || preg_match('/old\([^,()]*,/', $attrs['value']) === 1;
            }

            $out[] = [
                'name' => $name,
                'key' => $this->dotted($name),
                'marked' => $marked,
                'prefilled' => $prefilled,
                // `files[]` خانة واحدة شايلة المصفوفة كلها → بتورث إجبارية
                // الأب. أما `fam[{{ $p->id }}]` صف من جدول → إجبارية الأب
                // («على الأقل واحد») مش على كل صف.
                'inherits' => str_ends_with($name, '[]'),
            ];
        }

        return $out;
    }

    /** `items[0][qty]` / `items[{{ $i }}][qty]` / `ids[]` ⇐ `items.*.qty` / `ids.*` */
    private function dotted(string $name): string
    {
        $k = (string) preg_replace('/\[\d+\]|\[\{\{[^}]*\}\}\]|\[\]/', '.*', $name);

        return str_replace(['][', '[', ']'], ['.', '.', ''], $k);
    }

    /**
     * صفات تاج HTML — بتتحمّل تعبيرات بليد جوه القيم.
     *
     * @return array<string, string>
     */
    private function attrs(string $tag): array
    {
        $out = [];

        preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)(?:\s*=\s*(?:"((?:\{\{.*?\}\}|[^"])*)"|\'((?:\{\{.*?\}\}|[^\'])*)\'|([^\s"\'>]+)))?/s', $tag, $m, PREG_SET_ORDER);

        foreach ($m as $a) {
            if (isset($a[2]) && $a[2] !== '') {
                $out[strtolower($a[1])] = $a[2];
            } elseif (isset($a[3]) && $a[3] !== '') {
                $out[strtolower($a[1])] = $a[3];
            } elseif (isset($a[4])) {
                $out[strtolower($a[1])] = $a[4];
            } else {
                $out[strtolower($a[1])] = '';
            }
        }

        return $out;
    }

    private function stripBladeComments(string $src): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    }

    private function deliberate(array $form, string $key): bool
    {
        return isset(self::DELIBERATE[$form['key'].':'.$key])
            || isset(self::DELIBERATE[$form['view'].':'.$key]);
    }

    /**
     * حالة الخانة عند كل راوت من راوتات الفورم:
     * `required` · `conditional` · `optional` — والخانة اللي مالهاش
     * قاعدة أصلاً مش بتتعدّ (دي مشكلة تانية بيفحصها تيست تاني).
     *
     * @param  list<string>  $routes
     * @return list<string>
     */
    private function statusesAcross(array $routes, string $key, bool $inheritParent = true): array
    {
        $out = [];

        foreach ($routes as $name) {
            $rules = $this->rulesFor($name) ?? [];
            $status = $rules[$key] ?? null;

            // `files[]` ⇐ `files.*` — الإجبارية على الأب (`'files' => 'required|array'`)
            // والقاعدة الفرعية بتوصف كل عنصر. الأعلى منهم بيغلب.
            if ($inheritParent && str_ends_with($key, '.*')) {
                $parent = $rules[substr($key, 0, -2)] ?? null;
                $rank = ['optional' => 0, 'paired' => 1, 'conditional' => 2, 'required' => 3];

                if ($parent !== null && ($status === null || $rank[$parent] > $rank[$status])) {
                    $status = $parent;
                }
            }

            if ($status === null) {
                foreach ($rules as $ruleKey => $s) {
                    if (str_contains($ruleKey, '*')) {
                        $re = '/^'.str_replace(['\*', '\.'], ['[^.]+', '\.'], preg_quote($ruleKey, '/')).'$/';

                        if (preg_match($re, $key)) {
                            $status = $s;

                            break;
                        }
                    }
                }
            }

            if ($status !== null) {
                $out[] = $status;
            }
        }

        return $out;
    }

    // ═══════════════════ قواعد السيرفر ═══════════════════

    /** @var array<string, array<string, string>|null> */
    private array $rulesCache = [];

    /**
     * قواعد الراوت من مصدر الكنترولر: كل `validate([...])` في الميثود،
     * وكل `$this->xRules()` بتنادي عليها، وكل `$rules = [...]` محلي.
     *
     * @return array<string, string>|null  خانة ⇒ required|conditional|optional — null لو مش مقروءة
     */
    private function rulesFor(string $routeName): ?array
    {
        if (array_key_exists($routeName, $this->rulesCache)) {
            return $this->rulesCache[$routeName];
        }

        $route = Route::getRoutes()->getByName($routeName);
        $action = $route?->getActionName();

        if ($action === null || ! str_contains($action, '@')) {
            return $this->rulesCache[$routeName] = null;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! method_exists($class, $method)) {
            return $this->rulesCache[$routeName] = null;
        }

        $ref = new \ReflectionMethod($class, $method);
        $body = $this->methodSource($ref);
        $rules = [];

        foreach ($this->validateArguments($body) as $expr) {
            $rules = $this->mergeRules($rules, $this->rulesFromExpression($expr, $body, $ref->getDeclaringClass(), 0));
        }

        // ⚠️ `update()` بتنادي `$this->rules($request)` والهيلبر هو اللي
        // فيه `$request->validate([...])` — فالقواعد مش في الأكشن نفسه.
        if (preg_match_all('/\$this->(\w+)\(/', $body, $hm)) {
            foreach (array_unique($hm[1]) as $helper) {
                $rules = $this->mergeRules($rules, $this->rulesFromHelper($ref->getDeclaringClass(), $helper, 0));
            }
        }

        return $this->rulesCache[$routeName] = $rules === [] ? null : $rules;
    }

    private function methodSource(\ReflectionMethod $ref): string
    {
        $lines = file($ref->getFileName()) ?: [];
        $slice = array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1);

        return $this->stripPhpComments(implode('', $slice));
    }

    /**
     * نصوص الأرجيومنت الأول لكل `->validate(` في الميثود.
     *
     * @return list<string>
     */
    private function validateArguments(string $body): array
    {
        $out = [];
        $offset = 0;

        while (($pos = strpos($body, 'validate(', $offset)) !== false) {
            $open = $pos + strlen('validate(') - 1;
            $inner = $this->balanced($body, $open);
            $offset = $open + 1;

            if ($inner === null) {
                continue;
            }

            // الأرجيومنت الأول بس — اللي بعده رسايل وأسماء
            $out[] = $this->firstArgument($inner);
        }

        return $out;
    }

    /**
     * تعبير القواعد → خانة ⇒ حالة. بيفهم:
     *   `[...]` حرفية · `$this->helper(...)` · `$var` معرّف في نفس الميثود ·
     *   وجمعهم بـ`+` أو `array_merge`.
     *
     * @return array<string, string>
     */
    private function rulesFromExpression(string $expr, string $scope, \ReflectionClass $class, int $depth): array
    {
        if ($depth > 4) {
            return [];
        }

        $rules = [];
        $i = 0;
        $n = strlen($expr);

        while ($i < $n) {
            $ch = $expr[$i];

            if ($ch === '[') {
                $inner = $this->balanced($expr, $i);

                if ($inner === null) {
                    break;
                }

                $rules = $this->mergeRules($rules, $this->parseRuleArray($inner));
                $i += strlen($inner) + 2;

                continue;
            }

            if (substr($expr, $i, 7) === '$this->' && preg_match('/^\$this->(\w+)\(/', substr($expr, $i), $hm)) {
                $rules = $this->mergeRules($rules, $this->rulesFromHelper($class, $hm[1], $depth));
                $i += strlen($hm[0]);

                continue;
            }

            if ($ch === '$' && preg_match('/^\$(\w+)/', substr($expr, $i), $vm) && $vm[1] !== 'this' && $vm[1] !== 'request') {
                // `$rules = …;` في نفس الميثود
                if (preg_match('/\$'.$vm[1].'\s*=\s*(?!=)/', $scope, $am, PREG_OFFSET_CAPTURE)) {
                    $start = $am[0][1] + strlen($am[0][0]);
                    $assignment = $this->untilSemicolon($scope, $start);
                    $rules = $this->mergeRules($rules, $this->rulesFromExpression($assignment, $scope, $class, $depth + 1));
                }

                $i += strlen($vm[0]);

                continue;
            }

            if ($ch === '\'' || $ch === '"') {
                $i = $this->skipString($expr, $i);

                continue;
            }

            $i++;
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function rulesFromHelper(\ReflectionClass $class, string $method, int $depth): array
    {
        if (! $class->hasMethod($method)) {
            return [];
        }

        $ref = $class->getMethod($method);
        $body = $this->methodSource($ref);
        $rules = [];
        $offset = 0;

        // كل `return [...]` أو `return $x + [...]` في الهيلبر
        while (preg_match('/\breturn\b/', $body, $rm, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $rm[0][1] + strlen($rm[0][0]);
            $expr = $this->untilSemicolon($body, $start);
            $rules = $this->mergeRules($rules, $this->rulesFromExpression($expr, $body, $class, $depth + 1));
            $offset = $start;
        }

        // و`$rules['x'] = [...]` / `$rules += [...]` بعد التعريف
        if (preg_match_all('/\$rules(\[[^\]]*\])?\s*(\+?=)\s*/', $body, $am, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($am as $a) {
                $start = $a[0][1] + strlen($a[0][0]);
                $expr = $this->untilSemicolon($body, $start);

                if (($a[1][0] ?? '') !== '' && preg_match("/^\\['([^']+)'\\]$/", $a[1][0], $km)) {
                    $rules = $this->mergeRules($rules, [$km[1] => $this->statusOf($expr)]);
                } else {
                    $rules = $this->mergeRules($rules, $this->rulesFromExpression($expr, $body, $class, $depth + 1));
                }
            }
        }

        return $rules;
    }

    /**
     * `'key' => rules, …` على المستوى الأول من المصفوفة.
     *
     * @return array<string, string>
     */
    private function parseRuleArray(string $inner): array
    {
        $out = [];

        foreach ($this->splitTopLevel($inner) as $entry) {
            $entry = trim($entry);

            if ($entry === '' || ! str_contains($entry, '=>')) {
                continue;
            }

            [$keyPart, $valuePart] = explode('=>', $entry, 2);

            // المفتاح لازم يكون نص حرفي — `Client::X => …` أو `$k => …` مش خانة شاشة
            if (! preg_match('/^\s*[\'"]([^\'"]+)[\'"]\s*$/', $keyPart, $km)) {
                continue;
            }

            $out[$km[1]] = $this->statusOf($valuePart);
        }

        return $out;
    }

    /**
     * حالة خانة من نص قواعدها:
     *
     *   • `required` — دايماً.
     *   • `conditional` — `required_if` / `required_unless` /
     *     `Rule::requiredIf`: بتتحدد بقيمة **خانة تانية** (اعتماد/رفض،
     *     طريقة الدفع)، والمستخدم لازم يعرف إنها هتبقى لازمة → نجمة.
     *   • `paired` — `required_with` / `required_without`: مربوطة
     *     **بوجود** خانة تانية (أيام السداد ↔ أساسها). دي مش إجبارية
     *     بذاتها فالنجمة عليها تضليل، ومن غيرها مش سهو.
     *   • `optional` — الباقي.
     */
    private function statusOf(string $value): string
    {
        $status = 'optional';
        $rank = ['optional' => 0, 'paired' => 1, 'conditional' => 2, 'required' => 3];

        $raise = function (string $to) use (&$status, $rank): void {
            if ($rank[$to] > $rank[$status]) {
                $status = $to;
            }
        };

        foreach ($this->stringLiterals($value) as $literal) {
            foreach (explode('|', $literal) as $piece) {
                $piece = trim($piece);

                if ($piece === 'required') {
                    return 'required';
                }

                if (str_starts_with($piece, 'required_with') || str_starts_with($piece, 'required_without')
                    || str_starts_with($piece, 'required_array_keys')) {
                    $raise('paired');
                } elseif (str_starts_with($piece, 'required_') || str_starts_with($piece, 'required:')) {
                    $raise('conditional');
                }
            }
        }

        if (preg_match('/\brequiredIf\b/', $value)) {
            $raise('conditional');
        }

        return $status;
    }

    /** @return array<string, string> */
    private function mergeRules(array $base, array $extra): array
    {
        $rank = ['optional' => 0, 'paired' => 1, 'conditional' => 2, 'required' => 3];

        foreach ($extra as $k => $status) {
            if (! isset($base[$k]) || $rank[$status] > $rank[$base[$k]]) {
                $base[$k] = $status;
            }
        }

        return $base;
    }

    // ═══════════════════ أدوات نصية ═══════════════════

    /**
     * محتوى القوس المفتوح عند `$open` (`(` أو `[` أو `{`) لحد قفلته —
     * بيتخطى النصوص. `null` لو مش متزن.
     */
    private function balanced(string $src, int $open): ?string
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $close = $pairs[$src[$open]] ?? null;

        if ($close === null) {
            return null;
        }

        $depth = 0;
        $n = strlen($src);

        for ($i = $open; $i < $n; $i++) {
            $ch = $src[$i];

            if ($ch === '\'' || $ch === '"') {
                $i = $this->skipString($src, $i) - 1;

                continue;
            }

            if (isset($pairs[$ch])) {
                $depth++;
            } elseif (in_array($ch, $pairs, true)) {
                $depth--;

                if ($depth === 0) {
                    return substr($src, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    /** موضع أول حرف بعد النص اللي بيبدأ عند `$i` */
    private function skipString(string $src, int $i): int
    {
        $q = $src[$i];
        $n = strlen($src);
        $i++;

        while ($i < $n) {
            if ($src[$i] === '\\') {
                $i += 2;

                continue;
            }

            if ($src[$i] === $q) {
                return $i + 1;
            }

            $i++;
        }

        return $n;
    }

    /** @return list<string> */
    private function splitTopLevel(string $src): array
    {
        $out = [];
        $depth = 0;
        $buf = '';
        $n = strlen($src);

        for ($i = 0; $i < $n; $i++) {
            $ch = $src[$i];

            if ($ch === '\'' || $ch === '"') {
                $end = $this->skipString($src, $i);
                $buf .= substr($src, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $out[] = $buf;
                $buf = '';

                continue;
            }

            $buf .= $ch;
        }

        $out[] = $buf;

        return $out;
    }

    private function firstArgument(string $inner): string
    {
        return $this->splitTopLevel($inner)[0] ?? '';
    }

    private function untilSemicolon(string $src, int $start): string
    {
        $depth = 0;
        $n = strlen($src);

        for ($i = $start; $i < $n; $i++) {
            $ch = $src[$i];

            if ($ch === '\'' || $ch === '"') {
                $i = $this->skipString($src, $i) - 1;

                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
            } elseif ($ch === ';' && $depth === 0) {
                return substr($src, $start, $i - $start);
            }
        }

        return substr($src, $start);
    }

    /** @return list<string> */
    private function stringLiterals(string $src): array
    {
        $out = [];
        $n = strlen($src);

        for ($i = 0; $i < $n; $i++) {
            $ch = $src[$i];

            if ($ch === '\'' || $ch === '"') {
                $end = $this->skipString($src, $i);
                $out[] = substr($src, $i + 1, $end - $i - 2);
                $i = $end - 1;
            }
        }

        return $out;
    }

    /** بيشيل `// …` و`/* … *\/` و`# …` من غير ما يلمس النصوص */
    private function stripPhpComments(string $src): string
    {
        $out = '';
        $n = strlen($src);

        for ($i = 0; $i < $n; $i++) {
            $ch = $src[$i];

            if ($ch === '\'' || $ch === '"') {
                $end = $this->skipString($src, $i);
                $out .= substr($src, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($ch === '/' && ($src[$i + 1] ?? '') === '/') {
                $nl = strpos($src, "\n", $i);
                $i = $nl === false ? $n : $nl;
                $out .= "\n";

                continue;
            }

            if ($ch === '/' && ($src[$i + 1] ?? '') === '*') {
                $end = strpos($src, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;

                continue;
            }

            $out .= $ch;
        }

        return $out;
    }
}
