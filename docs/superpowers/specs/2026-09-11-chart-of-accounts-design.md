# شجرة الحسابات ودفتر الأستاذ العام — تصميم (١١ سبتمبر ٢٠٢٦)

## 1. الهدف

طبقة محاسبية مزدوجة فوق PROMAX تخدم الإدارة والمحاسب القانوني: شجرة حسابات
بالمعيار المصري، يومية قيود متوازنة، ميزان مراجعة وقائمة دخل وميزانية،
مصروفات وخزنة وبنك، تصحيح مُسجَّل، وإعادة بناء كاملة من المستندات الأصلية
بتاريخ بداية يحدده المالك.

**قرارات المالك (١١/٩):** الشجرة للإدارة + محاسب قانوني · خزنة رئيسية + بنك
واحد + نقدية مع كل مندوب · المصروفات في المرحلة الأولى بكل أشكالها بشاشة
بسيطة · التصحيح مباشر قبل قفل الفترة وعكسي بعده · المخزون وتكلفة البضاعة
**بره الشجرة** دلوقتي · تاريخ البداية قابل للتحكم وإعادة البناء تسمع كل
الداتا القديمة اللي تدخل بتاريخها.

## 2. العقيدة اللي التصميم واقف عليها

- **`transactions` يفضل مصدر الحقيقة للمديونية.** الشجرة **مشتقة**: كل صف
  في `transactions` بيولّد قيد يومية، ومش بيتغير أي فلو بيع/تحصيل/مرتجع.
- **فحص ثابت:** رصيد حساب «عملاء» في الشجرة = `SUM(clients.balance)` بالحرف
  (بعد `recalculate`)، ورصيد «موردون» = مجموع أرصدة `supplier_transactions`.
  الفحص ده بيتنفّذ بعد كل إعادة بناء وفي تيست دائم.
- **القيد لازم يتوازن** (`Σ debit == Σ credit`) — الحفظ بيرفض غير ده على
  مستوى السيرفس، مش الفورم بس.
- **القيود الآلية مالهاش مبلغ ولا تاريخ يدوي.** الغلط في الرقم بيتصلّح من
  المستند الأصلي (إعادة تسعير، تعديل تاريخ، تعديل بنود) والقيد بيتبعه.
  اللي بيتعدّل يدوياً في القيد الآلي هو **الحساب** بس.
- الأعمدة الزمنية الجديدة `dateTime()`؛ المايجريشنز محروسة بـ`hasTable`.

## 3. البيانات

### 3.1 `gl_accounts`
| عمود | نوع | ملاحظة |
|---|---|---|
| `id` | | |
| `code` | string(40) unique | هرمي: `1`, `11`, `1101`, `1110`, `1110.SLS-001` — **٤٠ حرف مش ٢٠**: كود المندوب لوحده ممكن يوصل ٢٠ حرف فحساب `1110.{code}` بيعدّي الحد |
| `name`, `name_en` | | `HasBilingualName` |
| `parent_id` | nullable FK self | |
| `type` | enum asset/liability/equity/revenue/expense | يُشتق من الجذر ويُخزَّن |
| `normal_side` | enum debit/credit | assets/expenses مدين، الباقي دائن |
| `is_system` | bool | حسابات النظام مقفول مسحها وتغيير كودها |
| `system_key` | string nullable unique | `cash_main`, `bank`, `rep_cash`, `receivables`, `withheld_tax`, `payables`, `vat_output`, `opening_equity`, `sales`, `sales_returns`, `discounts_allowed`, `suspense` (معلّق — لقيود `transfer` وأي مصدر بلا مندوب)، `expense_purchases`, `expense_fuel`, `expense_maintenance`, `expense_salaries`, `expense_commissions`, `expense_rent`, `expense_gifts`, `expense_other` — قواعد الترحيل بتشاور بالمفتاح مش بالكود |
| `user_id` | nullable FK users | لحسابات `rep_cash` الفرعية (حساب لكل مندوب ميداني) |
| `active` | bool | |
| `is_postable` | bool | الجذور والمجاميع غير قابلة للترحيل |

**الجذور الخمسة ثابتة**: `1` أصول · `2` خصوم · `3` حقوق ملكية · `4` إيرادات · `5` مصروفات.
حسابات النظام تتولد بـ`GlSeeder` (idempotent، آمن يتعاد) وبأمر `promax:gl-sync-reps`
اللي بيضيف حساب `1110.{code}` لكل مستخدم ميداني نشط جديد (وبيتنادى من إنشاء المستخدم)؛
المستخدم اللي مالوش `code` بياخد `1110.U{id}`. الحسابات الحرة (مش نظام) مسموح
تغيّر الكود أو الأب **جوه نفس الجذر بس** — نقل حساب من جذر لجذر مرفوض عشان
`type`/`normal_side` المخزّنين مايبقوش كدب.

### 3.2 `gl_entries`
| عمود | ملاحظة |
|---|---|
| `number` | `JV-1001+` (`HasDocumentNumber`) |
| `date` | تاريخ القيد (تاريخ المستند) |
| `memo` | البيان |
| `origin` | `auto` / `manual` / `reversal` / `opening` |
| `source_type`, `source_id` | المستند: `Transaction` / `Expense` / `CashMovement` / `RepSettlement` / `SupplierTransaction` — **unique** مع `origin=auto` (قيد آلي واحد لكل مستند) |
| `rule_key` | القاعدة اللي ولّدته (للمعاينة والتشخيص) |
| `reverses_entry_id` | للقيد العكسي |
| `period_key` | `YYYY-MM` مخزّن للفلترة والقفل |
| `created_by`, `edited_at`, `edited_by` | |

### 3.3 `gl_lines`
`entry_id`, `account_id`, `debit`, `credit` (واحد منهم صفر), `memo` nullable,
`overridden` bool (الحساب اتغيّر يدوياً عن قاعدة الترحيل), `rule_account_id`
(الحساب اللي القاعدة كانت هتحطه — عشان إعادة البناء تعرف تحافظ على التعديل).

### 3.4 `gl_line_overrides` (سجل تدقيق)
`line_id`, `from_account_id`, `to_account_id`, `user_id`, `at`, `note`.

### 3.5 `gl_posting_rules`
`key` (unique), `label`, `debit_key`, `credit_key`, `tax_key` nullable, `active`.
المفاتيح تشاور على `system_key` في الحسابات. الجدول يُعدَّل من شاشة الإعدادات
ويُقرأ بكاش يتفرّغ عند الحفظ. القواعد الأولية في القسم 4.

### 3.6 `gl_periods`
`key` (`YYYY-MM`) unique, `status` open/closed, `closed_at`, `closed_by`,
`reopened_at`, `reopened_by`. الشهر اللي مالوش صف = مفتوح.

### 3.7 `expenses` (سند مصروف)
`number` (`EXP-1001+`), `date`, `account_id` (حساب مصروف 5xxx قابل للترحيل),
`amount`, `paid_from` enum cash_main/bank/rep_cash, `paid_from_user_id`
nullable (المندوب لو `rep_cash`), `payee_type` supplier/employee/other,
`payee_supplier_id` nullable, `payee_user_id` nullable, `payee_name` nullable,
`reference`, `note`, `attachment_path`, `created_by`, `status` posted/void,
`voided_at`, `voided_by`.

### 3.8 `cash_movements` (حركة نقدية)
`number` (`CM-1001+`), `date`, `kind` enum deposit(خزنة→بنك)/withdraw(بنك→خزنة)/
rep_advance(خزنة→مندوب)/rep_return(مندوب→خزنة), `amount`, `user_id` nullable,
`reference`, `note`, `attachment_path`, `created_by`, `status`.

### 3.9 إعدادات (`settings`)
`gl_start_date` (تاريخ البداية) · `gl_bank_name` · `gl_enabled` (سويتش الترحيل
الآلي — مقفول لحد ما المالك يفعّله بعد الأرصدة الافتتاحية).

## 4. قواعد الترحيل الأولية

| `key` | المصدر | مدين | دائن |
|---|---|---|---|
| `tx.sale` | `transactions.kind=sale` | receivables (`debit`) | sales (`debit − tax`) + vat_output (`tax`) |
| `tx.collection.rep_cash` | collection · method=cash · مصدر Visit/Invoice/PO | rep_cash للمندوب | receivables |
| `tx.collection.bank` | collection · method ∈ transfer/cheque/card | bank | receivables |
| `tx.collection.office_cash` | collection · method=cash · بلا مصدر | cash_main | receivables |
| `tx.collection.auto` | collection · method فاضي (مقابل فاتورة كاش / PO كاش) | rep_cash للمندوب صاحب الفاتورة/الأمر (وإلا cash_main) | receivables |
| `tx.return` | return | sales_returns (`credit − tax`) + vat_output (`tax`) | receivables |
| `tx.refund` | refund | receivables | rep_cash للمندوب (وإلا cash_main) |
| `tx.rebate` | rebate | discounts_allowed | receivables |
| `tx.settlement` | settlement | discounts_allowed | receivables |
| `tx.transfer` | transfer | receivables أو دائن حسب الإشارة، الطرف الآخر `suspense` | |
| `tx.taxded` | taxded | withheld_tax | receivables |
| `tx.opening` | opening | receivables / opening_equity حسب الإشارة | |
| `tx.consignment` | consignment | — (لا قيد؛ المبلغ صفر) | |
| `settle.received` | `rep_settlements.received` | cash_main | rep_cash للمندوب |
| `sup.invoice` | `supplier_transactions.kind=invoice` | expense_purchases (افتراضي؛ يتعدّل) | payables |
| `sup.payment` | payment | payables | cash_main أو bank حسب `supplier_payments.method` |
| `sup.opening` | opening | payables / opening_equity حسب الإشارة | |
| `expense` | سند مصروف | `expenses.account_id` | cash_main / bank / rep_cash |
| `cash.*` | حركة نقدية | حسب النوع (جدول 3.8) | |

مندوب التحصيل يُستنتج: `source_type=Visit` → `visits.user_id` · `Invoice` →
`invoices.user_id` · `PurchaseOrder` → `purchase_orders.assigned_to` ·
`User` (مستند يدوي) → `source_id`. لو مفيش مندوب أو مالوش حساب → `cash_main`
مع تعليم القيد `needs_review`.

## 5. الخدمات

- **`App\Services\Gl\Ledger`** — المكان الوحيد للكتابة:
  `post(Model $source, ?User $by): ?GlEntry` (idempotent بالمصدر) · `repost($source)` ·
  `unpost($source)` · `manual(Carbon $date, string $memo, array $lines, User $by, string $origin = 'manual')` ·
  `reverse(GlEntry, User, ?Carbon, ?string)` ·
  `overrideAccount(GlLine, GlAccount, User, ?string $note)` (مباشر لو الفترة مفتوحة، وإلا
  عكسي + صحيح بتاريخ النهاردة) · `rebuild(Carbon $from, bool $keepOverrides, bool $dryRun, ?User $by): RebuildReport`.

  **التنفيذ خرج عن النص في النقط دي:**
  - `manual()` **بترفض حسابات المراقبة** (`receivables` / `payables`) على أي
    سطر — المديونية مصدرها `transactions` وبس، فقيد يدوي عليها كان هيكسر
    الفحص الثابت. الرفض على مستوى السيرفس ومغطّى بتيست، مش تحقّق فورم.
  - `overrideAccount()` بترفض حساب مراقبة **على الطرفين** (لا تنقل منه ولا إليه).
  - التصحيح في **فترة مقفولة** بيكتب صف `gl_line_overrides` على السطر الأصلي
    وبيعلّم النسخة المصحّحة `overridden` — يعني سجل التدقيق بيفضل على السطر
    اللي اتغيّر فعلاً مش على القيد العكسي.
  - `rebuild()` بتعيد بناء صفوف التدقيق (`gl_line_overrides`) مش بس الحسابات،
    وبترفض لو فيه فترة مقفولة (`ClosedPeriod`) تاريخها ≥ `from`، وفيها **فحص
    ثالث `entries`** بالمفتاح (source_type|source_id) مش بمقارنة عددين: كل
    مصدر اتمسح قيده لازم يرجّع له قيد جديد بعد التوليد، وأي مصدر فضل من غير
    بديل بيظهر في `missingSources` على التقرير (أول 50 مفتاح). أول إعادة
    بناء على دفتر فاضي (`deleted=0`, `created=N`) بتعدّي عادي — مفيش حاجة
    اتمسحت أصلاً فمفيش "مصدر ضاع" هنا، وده اللي بيخلّي تفعيل الدفتر لأول
    مرة على اللايف ممكن. المعاينة (`dryRun`) **مابترميش أبداً** — بترجّع
    التقرير بـ`ok=false` لو الفحص فشل؛ التنفيذ الحقيقي بيرمي
    `RebuildFailed($report)` (الرسالة بتسمّي الفحوصات اللي فشلت فعلاً، مش
    نص ثابت عن رصيد العملاء/الموردين) وبيعمل rollback.
- **الأوبزرفرز معزولة**: كل هوك جوه `try/catch` + `Log::error` — طبقة الشجرة
  **مابتوقّعش كتابة بيزنس أبداً** (بيع/تحصيل/مرتجع بيعدّي حتى لو الترحيل وقع).
  `repost()` برضه no-op طول ما `gl_enabled !== '1'`.
- **أدوات الفاتورة في الأدمن** (`OpsController`) بتنادي `repostInvoiceGl()`
  وحذف لكل موديل على حدة بدل `whereKey()->update()` الصامت؛ و`renumberInvoices`
  بتنادي `repostInvoiceGl()` **لكل فاتورة اتغيّر رقمها** — مش إعادة بناء كاملة:
  الترقيم بيغيّر نص البيان بس، وإعادة البناء كانت بتطبّق أي قاعدة اتغيّرت بعد
  الترحيل الأصلي على الدفتر كله وبترفض لو فيه فترة مقفولة. `repostInvoiceGl()`
  بترجّع `bool`، ولو قيد اتمسح من غير بديل الشاشة بتعرض `gl.repost_failed_n`
  جنب رسالة نجاح الترقيم.
- **المصروفات والنقدية** بتقبل **مستخدمين ميدانيين نشطين بس** في نقدية المندوب.
- `App\Services\Gl\Reports::balancesByAccount` هي مصدر أرصدة الشجرة والميزان.
- **٢٣ راوت `gl.*`** (مصروفات ٣ · شجرة ٤ · يومية ٣ · تقارير ٣ · إعدادات ٧ ·
  نقدية ٣)؛ الصلاحيات: `act.gl.post` (أدمن + محاسب) و`act.gl.admin` (أدمن بس).
- **`App\Services\Gl\Rules`** — يقرأ `gl_posting_rules` ويحوّل مصدر إلى سطور.

  **التنفيذ خرج عن النص في النقط دي:**
  - **الإشارة معمّمة على كل الأنواع** (مش opening/transfer بس): كل نوع له
    **اتجاه طبيعي** — مدين (`sale`, `refund`, `opening`, `transfer`) أو دائن
    (`collection`, `return`, `rebate`, `settlement`, `taxded`) — والقاعدة
    مكتوبة على الاتجاه ده. لو الصف اتسجّل على الطرف المعاكس (تسوية بمدين،
    فاتورة معكوسة بدائن) بيتقلب **المدين والدائن على كل السطور بما فيها سطر
    الضريبة**، مش بتبديل مفتاحين (اللي كان بيسيب سطر الضريبة في اتجاهه).
  - **مفيش سطر بحساب فاضي ولا حساب مش بيقبل ترحيل**: أي مفتاح ناقص/موقوف/
    مجموعة بيروح `suspense` والقيد بيتعلّم `needs_review` — في كل المصادر
    (`transaction`/`settlement`/`supplier`/`expense`/`cash`)، وكمان حساب
    المصروف الجاي من السند نفسه. `rep_cash` من غير مندوب → `cash_main` +
    `needs_review`. القرار: الترحيل مايوقفش بيع ولا تحصيل، والمحاسب بيشوف
    اللي محتاج تصليح في اليومية.
  - **شاشة القواعد هي الحارس الأول** (`SettingsController::saveRules`):
    الطرف المدين والدائن مطلوبين لكل قاعدة — إلا `expense.*` (المدين جاي من
    السند)؛ المفاتيح المسموحة حسابات ترحيل شغّالة؛ و`rep_cash` (مجموعة)
    مسموح بس في القواعد اللي ريزولفرها بيستنتج المندوب:
    `tx.collection.rep_cash` · `tx.collection.auto` · `tx.refund` ·
    `cash.rep_advance` · `cash.rep_return` (الطرفين) و`settle.received` ·
    `expense.rep_cash` (الطرف الدائن بس). الرفض باسم القاعدة، وبانر فوق
    الشاشة بيسرد القواعد الموقوفة أو الناقصة («الترحيل هيقف / إعادة البناء
    هترفض»).
  - **تاريخ البداية لما يتحرك لقدام** (`saveGeneral`): القيود `origin=auto`
    اللي قبل التاريخ الجديد بتتمسح في ترانزاكشن وبيتعرض عددها — من غير كده
    الشجرة بتفضل شايلة فترة برّه الدفتر والفحص الثابت بيقع.
    و`Ledger::invariants()` بتقارن **الطرفين من `gl_start_date`** (طرف الدفتر
    كان بيتحسب من غير فترة).
  - **الفترة المقفولة مابتمنعش القيد الآلي** — الطبقة دي مشتقة من المستند
    ومنعها كان بيسيب المستند من غير قيد ويكسر الفحص الثابت. `post()` بتعلّم
    `needs_review` والشاشة بتقول السبب باسمه (`gl.needs_review_closed`).
    القيد اليدوي/العكسي/التصحيح لسه ممنوعين (`assertOpen`).
  - **`repost()` بيحافظ على تحويلات الحسابات اليدوية** وصفوف التدقيق بتاعتها
    بنفس منطق `rebuild()` بالحرف — `captureOverrides()`/`reapplyOverrides()`
    هيلبرز مشتركة بين الاتنين. من غير كده تعديل تاريخ فاتورة أو إعادة ترقيمها
    كانت بترجّع سطر المحاسب حوّله من غير أثر.
- **`App\Services\Gl\Reports`** — ميزان المراجعة · كشف حساب · قائمة الدخل ·
  الميزانية، كلها من `gl_lines` بفترة.
- **الهوكات:** `TransactionObserver` (`created`/`deleted` → post/unpost).
  التعديلات اللي بتمشي بـ`whereKey()->update()` (تعديل التاريخ، الترقيم،
  التحويل لعميل تاني) بتنادي `Ledger::repost` صراحةً في أدوات الفاتورة.
  `RepSettlement`, `SupplierTransaction`, `Expense`, `CashMovement` observers
  بنفس الشكل. كل الهوكات بتحترم `gl_enabled` و`gl_start_date`.
- **إعادة البناء**: داخل `DB::transaction`: حفظ التعديلات اليدوية (مصدر +
  سطر + حساب) → مسح `origin=auto` من التاريخ → توليد من المستندات بترتيب
  التاريخ → إعادة تطبيق التعديلات على نفس المصدر ونفس الحساب الأصلي →
  الفحص (عملاء/موردون) → لو فشل rollback ورسالة بالفرق. المعاينة نفس
  المسار بـ`dryRun` بيطلّع: عدد يتمسح، عدد يتولد، فرق كل حساب.

## 6. الشاشات (مجموعة «الحسابات العامة» — أدمن ومحاسب)

1. **الشجرة** `gl.accounts` — هرمية قابلة للطي، رصيد الفترة لكل حساب،
   إضافة/تعديل/إيقاف، حسابات النظام تتعدّل بالاسم فقط.
2. **اليومية** `gl.entries` — من/إلى، مصدر، حساب، بحث؛ لينك للمستند الأصلي؛
   زرار «قيد يدوي» (سطور ديناميكية، ميزان حي، الحفظ يرفض غير المتوازن).
3. **كشف حساب** `gl.accounts.show` — افتتاحي + حركة + جاري، إكسيل.
4. **ميزان المراجعة** `gl.trial_balance` · **قائمة الدخل** `gl.income` ·
   **الميزانية** `gl.balance_sheet` — بفترة، إكسيل وطباعة.
5. **المصروفات** `gl.expenses` (+ سند جديد، إلغاء) و**حركة النقدية**
   `gl.cash` (+ سند جديد) — كلاهما بمرفق صورة.
6. **الإعدادات** `gl.settings` — قواعد الترحيل، تاريخ البداية، اسم البنك،
   السويتش، الفترات (قفل/فتح)، **إعادة البناء** (معاينة ثم تنفيذ).
7. **زرار ✎ على أي سطر** في اليومية وكشف الحساب — يغيّر الحساب (القسم 5).

كل الشاشات بالـtopbar العام (إكسيل الشاشة/PDF) وفلتر من/إلى بـ`DateRange`.
`Access::SCREENS` للأدمن والمحاسب، و`ACTIONS`: `act.gl.post` (قيد يدوي/مصروف/نقدية)
للاتنين، `act.gl.admin` (إعادة بناء، قواعد، فتح فترة) أدمن بس.

## 7. التصفية والمصروفات من نقدية المندوب

سند مصروف `paid_from=rep_cash` بيخصم من حساب المندوب فوراً. شاشة التصفية
بتعرض سطر «مصروفات معتمدة من نقدية المندوب في النافذة» ويقلل «المتوقع
تسليمه». حساب المندوب في الشجرة بعد التصفية = اللي لسه معاه (فرق متوقع/
مستلم) — وده تقرير جاهز «نقدية مع المناديب».

## 8. الأخطاء والحُرّاس

- قيد غير متوازن → استثناء `UnbalancedEntry` (422 في الفورم).
- سطر بمدين ودائن مع بعض → مرفوض (`gl.line_one_side`)؛ وسقف المبلغ
  `max:99999999` عشان القص الصامت في الداتابيز مايطلّعش قيد «متوازن» في
  الفورم ومش متوازن في الدفتر.
- تعديل/قيد يدوي بتاريخ في فترة مقفولة → مرفوض برسالة، والبديل العكسي معروض.
  **القيد الآلي استثناء**: بيترحّل ويتعلّم `needs_review` (القسم ٥).
- قاعدة ترحيل من غير طرفها أو بمفتاح مش حساب ترحيل → الحفظ مرفوض باسم
  القاعدة، وبانر على الشاشة بالقواعد الموقوفة/الناقصة قبل ما الترحيل يوقف.
- تاريخ بداية الدفتر اتحرك لقدام → القيود الآلية الأقدم بتتمسح والعدد بيتعرض.
- مسح حساب عليه قيود أو حساب نظام → مرفوض. إيقاف الحساب مسموح.
- إعادة البناء مع الفحص فاشل → rollback + جدول الفروق.
- مصروف من نقدية مندوب مالوش حساب → يتولد الحساب أوتوماتيك.
- كل الكتابة داخل `DB::transaction` مع `lockForUpdate` على المستند المصدر.

## 9. الاختبارات

- `GlPostingTest`: لكل نوع في `Transaction::KINDS` قيد متوازن بالحسابات
  الصح والضريبة منفصلة؛ مندوب التحصيل يُستنتج من المصادر الأربعة.
- `GlInvariantTest`: بعد عالم كامل (كاش/آجل/تحويل/مرتجع/رد/تصفية/مصروف)
  رصيد عملاء = Σ `clients.balance`، وميزان المراجعة يتوازن.
- `GlRebuildTest`: إعادة البناء تُرجع نفس الأرصدة بالحرف، وتحافظ على
  التعديل اليدوي، و«نظيفة» تمسحه، وتاريخ البداية يستثني القديم.
- `GlCorrectionTest`: تعديل مباشر في فترة مفتوحة يسجّل التدقيق؛ في فترة
  مقفولة يولّد عكسي + صحيح؛ القيد اليدوي غير المتوازن مرفوض.
- `ExpensesCashTest`: سند مصروف من الخزنة/البنك/نقدية مندوب + حركة نقدية +
  الإلغاء يعكس؛ التصفية تعرض المصروفات.
- الشاشات تدخل `RoleAccessTest`/`FormStarIntegrityTest` أوتوماتيك، وتيست
  `GlScreensTest` للرندر والتصدير.

## 10. خارج النطاق (مرحلة تانية)

تكلفة البضاعة والمخزون في الشجرة · شيكات تحت التحصيل · مصروفات المندوب من
الأبلكيشن بموافقة · الإقفال السنوي وترحيل الأرباح · مراكز تكلفة بالقناة/الفرع.
