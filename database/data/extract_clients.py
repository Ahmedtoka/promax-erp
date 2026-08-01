#!/usr/bin/env python3
"""
═══════════════════════════════════════════════════════════════
  قارئ شيتات العملاء → ملف داتا واحد
═══════════════════════════════════════════════════════════════

  الاستخدام:
      python3 database/data/extract_clients.py <مجلد الشيتات>

  بيطلّع:
      storage/app/data/clients_2026.json     الفروع الجاهزة للاستيراد
      storage/app/data/clients_review.json   اللي محتاج عين بشرية

  ⚠️ **الملف ده بيتشغّل على الجهاز مش على السيرفر.** ناتجه (الـJSON)
  هو اللي بيترفع على الجت، والأمر `promax:clients` بيقرا منه. السبب:
  الشيتات نفسها بتتغيّر ومحدش بيفتكر يعيد التشغيل، فلو الأمر بيقرا
  الشيتات مباشرةً كنا هنبقى بنستورد داتا مختلفة كل مرة من غير ما
  حد ياخد باله.

  ⚠️ **الـ24 شيت ليهم 24 شكل مختلف.** فيه اللي هيدر في أول سطر،
  واللي هيدر في السطر الخامس، واللي عمود واحد فيه الاسم والعنوان
  واللينك مخلوطين، واللي مكتوب رأسي (مفتاح: قيمة). محاولة قراية
  الكل بدالة واحدة «ذكية» كانت هتسيب صفوف من غير ما تقول، فكل
  سلسلة ليها دالة بتعرف شكلها.
"""

import json
import os
import re
import sys
import unicodedata

try:
    import openpyxl
except ImportError:
    sys.exit('محتاج openpyxl:  pip install openpyxl')


# ═══════════════════════════════════════════════════════════════
#  السلاسل — الكود والاسم
# ═══════════════════════════════════════════════════════════════
#
# ⚠️ **الكود بيدخل في كود كل فرع** (CRK-001)، فتغييره بعد الاستيراد
# معناه إن كل الفروع تاخد أكواد جديدة والشغل القديم يبقى منسوب
# لأكواد مالهاش صاحب.

CHAINS = {
    'CRK': ('Circle K', 'سيركل كيه'),
    'BEG': ('Bait El Gomla', 'بيت الجملة'),
    'MTR': ('Metro Market', 'مترو ماركت'),
    'GRM': ('Gourmet', 'جورميه'),
    'OTR': ('On The Run', 'أون ذا رن'),
    'HLT': ('Healthy Elite', 'هيلثي إيليت'),
    'QCK': ('Quick 24', 'كويك 24'),
    'FRS': ('Fresh Food', 'فريش فود'),
    'MRH': ('Marhba', 'مرحبا'),
    'MEX': ('Master Express', 'ماستر إكسبرس'),
    'QMK': ('Q Market', 'كيو ماركت'),
    'PKP': ('Pick Up', 'بيك أب'),
    'SVW': ('Seven Wings', 'سفن وينجز'),
    'TRF': ('Traffic', 'ترافيك'),
    'LVL': ('Live Lines', 'لايف لاينز'),
    'ZNM': ('Zone Mart', 'زون مارت'),
    'WMT': ('W Mart', 'دبليو مارت'),
    'MGS': ('Master Go Sofia', 'ماستر جو صوفيا'),
    'OSC': ('Oscar', 'أوسكار'),
    'FLM': ('Flamingo', 'فلامنجو'),
    'AMK': ('A Market', 'إيه ماركت'),
    'EXC': ('Exception Market', 'إكسبشن ماركت'),
    'AHB': ('Al Hussiny & New Benni', 'الحسيني ونيو بيني'),
}


# ═══════════════════════════════════════════════════════════════
#  المحافظات — من اسم المنطقة للمفتاح
# ═══════════════════════════════════════════════════════════════
#
# ⚠️ المفاتيح دي لازم تطابق `App\Support\Governorates::KEYS` بالحرف.
# مفتاح مش موجود هناك بيتخزن في العمود وبيطلع فاضي في كل شاشة.

GOV_HINTS = [
    ('cairo', ['cairo', 'maadi', 'helwan', 'nasr city', 'heliopolis', 'mokkatam',
               'mokattam', 'abbasia', 'new cairo', 'settlement', 'tagamo', 'rehab',
               'madinaty', 'shorouk', 'obour', 'katamya', 'qattamiyah', 'zamalek',
               'downtown', 'shubra', 'ring road', 'elshahed', 'elmoshier', 'salam',
               'sheraton', 'masr elgadida', 'القاهرة', 'المعادي', 'مدينة نصر',
               'مصر الجديدة', 'المقطم', 'التجمع', 'الرحاب', 'مدينتي', 'الشروق',
               'العبور', 'الزمالك', 'شبرا', 'حلوان', 'زهراء', 'وسط البلد',
               'النرجس', 'البنفسج', 'الشويفات', 'السوق الشرقي', 'دار مصر',
               'القرنفل', 'الياسمين', 'اللوتس', 'شيراتون', 'المعراج',
               'الاندلس', 'الهضبه', 'الهضبة', 'المستقبل', 'رمسيس',
               'صلاح سالم', 'الماظه', 'الماظة', 'عين شمس', 'المرج',
               'الدراسة', 'السيده', 'الحلميه', 'روكسي', 'العباسيه',
               'التحرير', 'الاهلي', 'الاهرام', 'مصر القديمه',
               'new capital', 'autostourad', 'autostrad', 'العاصمه الاداريه',
               'العاصمة الإدارية', 'اوتوستراد']),
    ('giza', ['giza', 'october', '6th of october', 'sheikh zayed', 'zayed', 'haram',
              'dokki', 'mohandes', 'faisal', 'agouza', 'imbaba', 'الجيزة', 'أكتوبر',
              'اكتوبر', 'الشيخ زايد', 'الهرم', 'الدقي', 'المهندسين', 'فيصل',
              'العجوزة', 'امبابة', 'بولاق', 'الوراق', 'حدائق الاهرام',
              'حدائق الأهرام', 'بالم هيلز', 'زايد', 'المنيب']),
    ('qalyubia', ['qalyub', 'banha', 'shubra el kheima', 'القليوبية', 'بنها']),
    ('alexandria', ['alex', 'alexandria', 'moharam', 'miami', 'smouha', 'stefano',
                    'الاسكندرية', 'الإسكندرية', 'اسكندرية', 'ميامي', 'سان ستيفانو',
                    'العجمي', 'سيدي بشر', 'المنشية', 'الورديان', 'مينا البصل',
                    'العصافرة', 'باكوس', 'الحضرة', 'محرم بك', 'الثغر',
                    'خير الله', 'ونجت', 'الفلكي', 'سموحة', 'كليوباترا',
                    'الساعة', 'الصداقة', 'برج']),
    ('beheira', ['nobarya', 'elnobarya', 'damanhour', 'البحيرة', 'النوبارية']),
    # ⚠️ «Noarth Coast» مكتوبة غلط في الشيت — بندخّل الغلط زي ما هو
    # لأن الهدف مطابقة اللي مكتوب، مش تصحيح إملاء المصدر.
    ('matrouh', ['matrouh', 'matrooh', 'marsa', 'sidi abdel', 'مطروح', 'مرسى',
                 'north coast', 'noarth coast', 'alamein', 'marina', 'ras elhekma',
                 'hacienda', 'marassi', 'elhammam', 'الساحل الشمالي', 'العلمين']),
    ('dakahlia', ['mansoura', 'mansora', 'الدقهلية', 'المنصورة']),
    ('kafr_el_sheikh', ['kafr elsheikh', 'kafr el sheikh', 'كفر الشيخ']),
    ('gharbia', ['tanta', 'mahalla', 'الغربية', 'طنطا', 'المحلة']),
    ('sharqia', ['zagazig', '10th of ramadan', 'sharqia', 'belbeis',
                 'العاشر من رمضان', 'الشرقية', 'الزقازيق', 'بلبيس']),
    ('monufia', ['shibin', 'menoufia', 'المنوفية']),
    ('damietta', ['damietta', 'دمياط']),
    ('ismailia', ['ismailia', 'ismallia', 'الإسماعيلية', 'الاسماعيلية']),
    ('port_said', ['port said', 'بورسعيد', 'بور سعيد']),
    ('suez', ['suez', 'sokhna', 'galala', 'السويس', 'السخنة', 'سخنة', 'الجلالة']),
    ('south_sinai', ['sharm', 'dahab', 'nuweiba', 'شرم', 'دهب', 'طابا']),
    ('north_sinai', ['arish', 'العريش']),
    ('red_sea', ['hurghada', 'ras ghareb', 'gouna', 'الغردقة', 'رأس غارب', 'راس غارب']),
    ('faiyum', ['faiyum', 'fayioum', 'fayoum', 'الفيوم']),
    ('beni_suef', ['beni suef', 'بني سويف']),
    ('minya', ['minya', 'المنيا']),
    ('asyut', ['assuit', 'asyut', 'assiut', 'أسيوط', 'اسيوط']),
    ('sohag', ['sohag', 'سوهاج']),
    ('qena', ['qena', 'قنا']),
    ('luxor', ['luxor', 'الأقصر', 'الاقصر']),
    ('aswan', ['aswan', 'أسوان', 'اسوان']),
]


def governorate(*parts) -> str | None:
    """المحافظة من أي نص متاح — المنطقة أو العنوان."""
    blob = ' '.join(str(p) for p in parts if p).lower()

    for key, hints in GOV_HINTS:
        for h in hints:
            if h in blob:
                return key

    return None


# ═══════════════════════════════════════════════════════════════
#  أدوات
# ═══════════════════════════════════════════════════════════════

AR = re.compile(r'[؀-ۿ]')


def clean(v) -> str:
    """نص نضيف: مسافات مظبوطة، ومفيش None."""
    if v is None:
        return ''

    s = str(v).strip()
    s = s.replace('‏', '').replace('‎', '')     # علامات الاتجاه
    s = unicodedata.normalize('NFKC', s)
    s = re.sub(r'\s+', ' ', s)

    return s.strip(' -–—:،,')


def is_arabic(s: str) -> bool:
    return bool(AR.search(s or ''))


def phone(v) -> str:
    """
    تليفون مصري بشكل موحّد.

    ⚠️ إكسل بيحوّل `01012345678` لرقم وبيوقّع الصفر الأول. بنرجّعه —
    من غيره الرقم بيبقى 10 أرقام والمندوب بيتصل ومايردش عليه حد.
    """
    s = clean(v)

    if not s or s.lower() in ('nan', 'none', '-'):
        return ''

    s = re.sub(r'[^\d+]', '', s.replace('+20', '0'))

    if s.startswith('20') and len(s) > 11:
        s = '0' + s[2:]

    if len(s) == 10 and s[0] == '1':
        s = '0' + s

    return s if 10 <= len(s) <= 13 else ''


def maps_url(*vals) -> str:
    for v in vals:
        s = clean(v)
        if 'goo.gl' in s or 'google.com/maps' in s:
            return s.split(' ')[0]
    return ''


def norm_key(s: str) -> str:
    """
    مفتاح المقارنة للتكرار.

    ⚠️ **بيشيل الهمزات والتاء المربوطة والمسافات.** «مصر الجديدة»
    و«مصر الجديده» نفس المكان، و`==` العادية بتقول لأ.
    """
    s = (s or '').lower()
    s = re.sub(r'[أإآا]', 'ا', s)
    s = s.replace('ة', 'ه').replace('ى', 'ي').replace('ؤ', 'و').replace('ئ', 'ي')
    s = re.sub(r'[^\w؀-ۿ]', '', s)

    return s


# ═══════════════════════════════════════════════════════════════
#  القراءة
# ═══════════════════════════════════════════════════════════════

ROWS: list[dict] = []
REVIEW: list[dict] = []


def add(chain, name_en='', name_ar='', address='', area='', phone_no='',
        manager='', url='', src='', note=''):
    """
    بند واحد.

    ⚠️ **الفرع اللي مالوش اسم بيترفض.** صف من غير اسم بيتحوّل لعميل
    اسمه فاضي في القايمة، ومحدش بيعرف يوصله ولا يشيله.
    """
    name_en, name_ar = clean(name_en), clean(name_ar)

    if not name_en and not name_ar:
        return

    # الاسم العربي في خانة الإنجليزي — بيحصل كتير في الشيتات
    if name_en and is_arabic(name_en) and not name_ar:
        name_ar, name_en = name_en, ''

    ROWS.append({
        'chain': chain,
        'name_en': name_en,
        'name_ar': name_ar,
        'address': clean(address),
        'area': clean(area),
        'phone': phone(phone_no),
        'manager': clean(manager),
        'location_url': maps_url(url, address),
        'governorate': governorate(area, name_en, name_ar, address),
        'source': src,
        'note': note,
    })


def sheet(path, name=None):
    wb = openpyxl.load_workbook(path, data_only=True, read_only=True)
    ws = wb[name] if name else wb.worksheets[0]
    rows = [list(r) for r in ws.iter_rows(values_only=True)]
    wb.close()

    return rows


def header_at(rows, *must):
    """أول سطر فيه كل الكلمات دي — الهيدر مش دايماً السطر الأول."""
    for i, r in enumerate(rows[:12]):
        blob = ' '.join(clean(c).lower() for c in r if c)
        if all(m in blob for m in must):
            return i
    return 0


def body(rows, hdr, width=4, max_gap=1):
    """
    صفوف الداتا بس — بتقف عند نهاية الجدول الحقيقية.

    ⚠️ **الشيتات فيها جداول تانية تحت الداتا.** «All Stores» في شيت
    Circle K بتخلص عند صف 200، وبعدها سطرين فاضيين وجدول تليفونات
    وإيميلات مديرين المناطق. القراية لآخر الملف كانت بتطلّع 17 «فرع»
    أسماؤهم إيميلات وأسماء مديرين — عملاء وهميين بأكواد حقيقية.

    ⚠️ **بس السطر الفاضي الواحد مش نهاية.** شيت On The Run بيفصل
    الفروع بسطر فاضي بين كل مجموعة، فالوقوف عند أول فراغ كان بيقطع
    الشيت عند الفرع السابع ويضيّع 26 فرع في صمت. القاعدة: فراغ واحد
    بيتعدّى، فراغين ورا بعض معناهم إن الجدول خلص.
    """
    out, gap = [], 0

    for r in rows[hdr + 1:]:
        if not any(clean(c) for c in r[:width]):
            gap += 1
            if gap > max_gap:
                break
            continue

        gap = 0
        out.append(r)

    return out


def cols(rows, hdr):
    """اسم العمود ← رقمه."""
    return {clean(c).lower(): i for i, c in enumerate(rows[hdr]) if clean(c)}


def pick(cmap, *names):
    for n in names:
        for k, i in cmap.items():
            if k.startswith(n):
                return i
    return None


def val(row, idx):
    if idx is None or idx >= len(row):
        return ''
    return clean(row[idx])


# ═══════════════════════════════════════════════════════════════
#  Circle K — أعقد سلسلة: ملفين و20 شيت
# ═══════════════════════════════════════════════════════════════

def circle_k(base):
    """
    ⚠️ **Circle K في ملفين والداتا متداخلة.**
      `01 … Data.xlsx` → «All Stores» فيها الـ217 فرع كلهم (المصدر)،
                         و5 شيتات مناطق فيها التليفون والعنوان،
                         و«Sub Franchise» فيها عناوين كمان.
      `02 - CircleK.xlsx` → 13 شيت بالمنطقة، **نفس الفروع** من غير
                         عنوان ولا تليفون.

    فالمنطق: «All Stores» هي القايمة، والباقي **بيغذّيها** بالتليفون
    والعنوان بمطابقة الاسم. لو قرينا كل شيت كقايمة مستقلة كنا
    هنطلّع نفس الفرع 3 مرات بـ3 أكواد.
    """
    extra: dict[str, dict] = {}

    # ── التليفونات والعناوين من شيتات المناطق ──
    for sh in ['KHATAB ARDY', 'AzizOctoberGiza', 'HOSSAM NEW CAIRO ', 'ALEX', 'UNUSUAL']:
        try:
            rows = sheet(base + '01 - Circle K Data.xlsx', sh)
        except KeyError:
            continue

        h = header_at(rows, 'store')
        c = cols(rows, h)
        ci = pick(c, 'store')
        for r in body(rows, h):
            nm = val(r, ci)
            if nm:
                extra[norm_key(nm)] = {
                    'phone': val(r, pick(c, 'number')),
                    'address': val(r, pick(c, 'location')),
                    'manager': val(r, pick(c, 'manager')),
                    'area': val(r, pick(c, 'area')),
                }

    # ── عناوين إضافية من Sub Franchise ──
    rows = sheet(base + '01 - Circle K Data.xlsx', 'Sub Franchise')
    h = header_at(rows, 'site name')
    c = cols(rows, h)
    for r in body(rows, h):
        nm = val(r, pick(c, 'site name'))
        k = norm_key(nm)
        if nm and k not in extra:
            extra[k] = {'address': val(r, pick(c, 'store address')), 'area': val(r, pick(c, 'area'))}

    # ── القايمة الرئيسية ──
    rows = sheet(base + '01 - Circle K Data.xlsx', 'All Stores')
    h = header_at(rows, 'store name', 'district')
    c = cols(rows, h)

    for r in body(rows, h):
        nm = val(r, pick(c, 'store name'))
        if not nm:
            continue

        e = extra.get(norm_key(nm), {})
        area = val(r, pick(c, 'district')) or e.get('area', '')

        add('CRK',
            name_en=nm,
            address=e.get('address', ''),
            area=area,
            phone_no=e.get('phone', ''),
            manager=val(r, pick(c, 'store manager')) or e.get('manager', ''),
            src='01 - Circle K Data.xlsx / All Stores',
            note=val(r, pick(c, 'company name')))


# ═══════════════════════════════════════════════════════════════
#  بيت الجملة — عمود واحد مخلوط
# ═══════════════════════════════════════════════════════════════

def bait_el_gomla(base):
    """
    ⚠️ **الشيت ده عمود واحد فيه كل حاجة.** الاسم والعنوان واللينك
    كل واحد في سطر لوحده، والفروع مرقّمة «1- فرع الوردian : العنوان».
    مفيش أي هيدر ولا أعمدة، فالقراية بالنمط مش بالعمود.
    """
    rows = sheet(base + '03 - Bait El Gomla .xlsx')
    lines = [clean(r[0]) for r in rows if r and clean(r[0])]

    pending = None
    section = ''      # «فروع اسكندرية» — بتحدد منطقة اللي بعدها

    for ln in lines:
        if 'goo.gl' in ln or 'google.com/maps' in ln:
            if pending:
                pending['url'] = ln.split(' ')[0]
            continue

        # «7- فرع عرامة : العصافرة قبلي : شارع 30…»
        m = re.match(r'^\d+\s*[-.]?\s*(?:فرع\s*)?([^:]+?)\s*:\s*(.+)$', ln)
        if m:
            if pending:
                add('BEG', **pending)
            pending = {'name_ar': m.group(1), 'address': m.group(2),
                       'area': section, 'src': '03 - Bait El Gomla .xlsx'}
            continue

        # ⚠️ **عنوان القسم بيحدد المحافظة لكل اللي بعده.** الفروع
        # مكتوبة «7- فرع عرامة : شارع 30…» من غير أي ذكر للمدينة،
        # فمن غير القسم كل فروع الإسكندرية كانت بتطلع بمحافظة فاضية.
        if ln.startswith('فروع') or ln.startswith('مخزن') or ln.startswith('الإدارة'):
            # ⚠️ **«فروع اسكندرية» عنوان قسم مش اسم منطقة.** لو دخل
            # زي ما هو، بيتعمل في قايمة المناطق «فروع اسكندرية» جنب
            # «الإسكندرية» — منطقتين لنفس المكان، والتقرير بالمنطقة
            # بيدّي صفّين.
            section = re.sub(r'^(فروع|مخزن فروع|مخزن|الإدارة المركزية)\s*', '', ln).strip()
            continue

        if pending and len(ln) > 25:
            pending['address'] = (pending.get('address', '') + ' ' + ln).strip()

    if pending:
        add('BEG', **pending)


# ═══════════════════════════════════════════════════════════════
#  مرحبا — مكتوب رأسي (مفتاح: قيمة)
# ═══════════════════════════════════════════════════════════════

def marhba(base):
    """
    ⚠️ **الشيت رأسي مش أفقي.** كل فرع 5 سطور: عنوان، «Branch Name:»،
    «Branch Manager's Name:»، «Location:»، «Phone Number:». قراية
    عمودية عادية كانت هتطلّع «Branch Name:» كاسم فرع 25 مرة.
    """
    rows = sheet(base + '10 - Marhba.xlsx')
    cur: dict = {}

    for r in rows:
        k, v = clean(r[0] if r else ''), clean(r[1] if r and len(r) > 1 else '')
        kl = k.lower()

        if kl.startswith('branch name'):
            if cur.get('name_en'):
                add('MRH', src='10 - Marhba.xlsx', **cur)
            cur = {'name_en': v}
        elif kl.startswith('branch manager'):
            cur['manager'] = v
        elif kl.startswith('location'):
            cur['url'] = v
        elif kl.startswith('phone'):
            cur['phone_no'] = v

    if cur.get('name_en'):
        add('MRH', src='10 - Marhba.xlsx', **cur)


# ═══════════════════════════════════════════════════════════════
#  الباقي — أشكال منتظمة
# ═══════════════════════════════════════════════════════════════

def simple(base, fname, chain, name_col, addr_col=None, area_col=None,
           phone_col=None, mgr_col=None, url_col=None, sheet_name=None, must=None):
    """شيت فيه هيدر وأعمدة — الشكل الغالب."""
    rows = sheet(base + fname, sheet_name)
    h = header_at(rows, *(must or [name_col]))
    c = cols(rows, h)

    ni = pick(c, name_col)
    if ni is None:
        return

    for r in body(rows, h):
        nm = val(r, ni)
        if not nm or nm.lower() in ('total', 'name'):
            continue

        add(chain,
            name_en=nm,
            address=val(r, pick(c, addr_col)) if addr_col else '',
            area=val(r, pick(c, area_col)) if area_col else '',
            phone_no=val(r, pick(c, phone_col)) if phone_col else '',
            manager=val(r, pick(c, mgr_col)) if mgr_col else '',
            url=val(r, pick(c, url_col)) if url_col else '',
            src=fname)


def read_all(base):
    circle_k(base)
    bait_el_gomla(base)
    marhba(base)

    simple(base, '04 - Metro Market.xlsx', 'MTR', 'site_name',
           addr_col='address', area_col='المحافظة', must=['site_name'])
    simple(base, '05 - Gourmet Stores Locations.xlsx', 'GRM', 'store name',
           addr_col='address', area_col='area', mgr_col='store manager',
           phone_col='mobile', must=['store name'])
    simple(base, '06 - On The Run Sites 2024.xlsx', 'OTR', 'site',
           addr_col='address', mgr_col='site manager', phone_col='mobile',
           must=['site', 'address'])
    simple(base, '07 - Healthy Milk .xlsx', 'HLT', 'branch name',
           addr_col='address', url_col='location', must=['branch name'])
    simple(base, '08  - QUICK 24.xlsx', 'QCK', 'branch',
           addr_col='address', phone_col='phone 1', must=['branch'])
    simple(base, '09 - Fresh Food.xlsx', 'FRS', 'الفرع',
           addr_col='العنوان', must=['الفرع'])
    simple(base, '11 - MasterExpress.xlsx', 'MEX', 'branch',
           addr_col='address', url_col='gps', must=['branch', 'address'])

    # ⚠️ الشيتات الصغيرة كلها `Name | Address` — والاسم فيها هو اسم
    # السلسلة مكرر، فالفرع بيتعرّف بعنوانه. بنستخدم العنوان كاسم
    # مبدئي عشان مايبقاش عندنا 14 عميل اسمهم «PICK UP».
    for fname, code in [
        ('12 - Q Market.xlsx', 'QMK'), ('13 - PICK UP.xlsx', 'PKP'),
        ('14 - Seven wings.xlsx', 'SVW'), ('15 - Traffic.xlsx', 'TRF'),
        ('16 - Live Lines.xlsx', 'LVL'), ('17 - Zone Mart.xlsx', 'ZNM'),
        ('18 - W Mart.xlsx', 'WMT'), ('19 - Master Go Sofia.xlsx', 'MGS'),
        ('20 - Oscar.xlsx', 'OSC'), ('21 - Flamingo.xlsx', 'FLM'),
        ('22 - A Market.xlsx', 'AMK'), ('23 - Exception Market.xlsx', 'EXC'),
        ('24 - Al Hussiny & New Benni.xlsx', 'AHB'),
    ]:
        rows = sheet(base + fname)
        h = header_at(rows, 'name')
        c = cols(rows, h)
        ni, ai = pick(c, 'name'), pick(c, 'address')

        for r in body(rows, h, 2):
            addr = val(r, ai)
            if not addr:
                continue

            # ⚠️ الاسم في العمود هو اسم السلسلة، فاسم الفرع = عنوانه.
            add(code, name_ar=addr if is_arabic(addr) else '',
                name_en='' if is_arabic(addr) else addr,
                address=addr, src=fname)


# ═══════════════════════════════════════════════════════════════
#  التكرار
# ═══════════════════════════════════════════════════════════════

def dedupe():
    """
    الاسم + العنوان.

    ⚠️ **المطابق التام بيتدمج، والمشتبه فيه بيتساب.** فرعين بنفس
    الاسم وعنوانين مختلفين ممكن يكونوا نفس المكان مكتوب بطريقتين،
    وممكن يكونوا فرعين حقيقيين على بعد شارع. دمجهم غلط بيمسح عميل
    من غير أثر، فبنسيبهم الاتنين ونحطهم في تقرير المراجعة.
    """
    seen: dict[tuple, dict] = {}
    out: list[dict] = []

    for r in ROWS:
        key = (r['chain'], norm_key(r['name_en'] or r['name_ar']), norm_key(r['address']))

        if key in seen:
            # ⚠️ الأغنى بيكسب: الصف اللي فيه تليفون أو عنوان أطول
            # هو اللي بيفضل، عشان المطابقة مع شيت تاني تزوّد بيانات
            # مش تقصّها.
            old = seen[key]
            for f in ('phone', 'address', 'manager', 'location_url', 'governorate', 'area'):
                if not old.get(f) and r.get(f):
                    old[f] = r[f]
            old.setdefault('merged', []).append(r['source'])
            continue

        seen[key] = r
        out.append(r)

    # ── المشتبه فيه: نفس السلسلة ونفس الاسم بعناوين مختلفة ──
    by_name: dict[tuple, list] = {}
    for r in out:
        by_name.setdefault((r['chain'], norm_key(r['name_en'] or r['name_ar'])), []).append(r)

    for (chain, _), group in by_name.items():
        if len(group) > 1:
            for r in group:
                REVIEW.append({
                    'why': 'نفس الاسم في نفس السلسلة بعناوين مختلفة — راجع لو ده نفس الفرع',
                    'chain': chain,
                    'name': r['name_en'] or r['name_ar'],
                    'address': r['address'],
                    'source': r['source'],
                })

    return out


# ═══════════════════════════════════════════════════════════════
#  الأكواد والإخراج
# ═══════════════════════════════════════════════════════════════

def finalise(rows):
    rows.sort(key=lambda r: (r['chain'], norm_key(r['name_en'] or r['name_ar'])))

    n: dict[str, int] = {}
    for r in rows:
        n[r['chain']] = n.get(r['chain'], 0) + 1
        r['code'] = f"{r['chain']}-{n[r['chain']]:03d}"

        # ⚠️ **الاسم الإنجليزي مابيتخترعش.** الفرع اللي اسمه عربي بس
        # بيتساب `name_en` فاضي وبيتحط في المراجعة. ترجمة آلية لاسم
        # مكان بتطلّع اسم يبان رسمي وهو غلط، وبيتطبع على الفواتير.
        if not r['name_en']:
            REVIEW.append({
                'why': 'اسم إنجليزي ناقص — السيستم هيعرضه بالعربي لحد ما تكتبه',
                'chain': r['chain'], 'code': r['code'],
                'name': r['name_ar'], 'address': r['address'], 'source': r['source'],
            })

        if not r['governorate']:
            REVIEW.append({
                'why': 'محافظة مش متعرّفة من العنوان',
                'chain': r['chain'], 'code': r['code'],
                'name': r['name_en'] or r['name_ar'],
                'address': r['address'], 'source': r['source'],
            })

    return rows


def main():
    base = sys.argv[1] if len(sys.argv) > 1 else ''
    if not base:
        sys.exit('محتاج مسار مجلد الشيتات')
    base = base.rstrip('/\\') + '/'

    read_all(base)
    rows = finalise(dedupe())

    here = os.path.dirname(os.path.abspath(__file__))
    out = os.path.abspath(os.path.join(here, '..', '..', 'storage', 'app', 'data'))
    os.makedirs(out, exist_ok=True)

    payload = {
        'chains': [{'code': c, 'name_en': en, 'name_ar': ar} for c, (en, ar) in CHAINS.items()],
        'clients': rows,
    }
    with open(os.path.join(out, 'clients_2026.json'), 'w', encoding='utf-8') as f:
        json.dump(payload, f, ensure_ascii=False, indent=1)
    with open(os.path.join(out, 'clients_review.json'), 'w', encoding='utf-8') as f:
        json.dump(REVIEW, f, ensure_ascii=False, indent=1)

    # ── التقرير ──
    print(f'\n  قرينا {len(ROWS)} صف → {len(rows)} فرع بعد التكرار\n')

    per: dict[str, int] = {}
    for r in rows:
        per[r['chain']] = per.get(r['chain'], 0) + 1

    for c, cnt in sorted(per.items(), key=lambda x: -x[1]):
        have = sum(1 for r in rows if r['chain'] == c and r['address'])
        ph = sum(1 for r in rows if r['chain'] == c and r['phone'])
        print(f'  {c}  {CHAINS[c][0]:26} {cnt:4} فرع   عنوان {have:4}   تليفون {ph:4}')

    kinds: dict[str, int] = {}
    for r in REVIEW:
        kinds[r['why']] = kinds.get(r['why'], 0) + 1

    print(f'\n  للمراجعة: {len(REVIEW)}')
    for k, v in kinds.items():
        print(f'    · {v:4}  {k}')
    print()


if __name__ == '__main__':
    main()
