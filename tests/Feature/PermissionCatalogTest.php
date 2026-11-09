<?php

namespace Tests\Feature;

use App\Support\Access;
use App\Support\PermissionCatalog as C;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_every_group_page_and_action_has_ar_and_en_description(): void
    {
        $missing = [];

        $own = array_merge(C::groups(), C::pages(), C::actions());

        foreach ($own as $key) {
            foreach (['ar', 'en'] as $loc) {
                $e = trans(C::langKey($key), [], $loc);

                if (! is_array($e) || empty($e['what']) || empty($e['data'])) {
                    $missing[] = "$loc:$key";
                }
            }
        }

        $this->assertSame([], $missing, 'missing: '.implode(' ', $missing));
    }

    public function test_every_key_has_a_valid_risk(): void
    {
        $this->assertNotEmpty(C::keys());

        foreach (C::keys() as $key) {
            $this->assertContains(C::risk($key), C::LEVELS, $key);
        }

        foreach (array_keys(C::RISK) as $key) {
            $this->assertContains($key, C::keys(), "RISK has unknown key $key");
        }
    }

    public function test_brief_risk_floor_and_defaults(): void
    {
        foreach (['act.overview.wipe', 'act.overview.demo', 'act.prices.propagate', 'act.gl.admin', 'act.costing.admin', 'act.hr.payroll_approve', 'act.hr.settings', 'act.team.manage', 'act.import.run', 'act.custody.adjust', 'act.field.wipe', 'act.products.delete'] as $k) {
            $this->assertSame('critical', C::risk($k), $k);
        }

        foreach (['docs.index', 'docs.file', 'nav.group_docs', 'notifications', 'attendance.punch', 'hr.leave', 'hr.permissions'] as $k) {
            $this->assertSame('low', C::risk($k), $k);
        }

        $this->assertSame('medium', C::risk('nav.group_home'));
        $this->assertSame('medium', C::risk('erp.overview'));
        $this->assertSame('high', C::risk('act.clients.create'));

        foreach (Access::LOCKED as $k) {
            $this->assertSame('critical', C::risk($k));
        }
    }

    public function test_screens_fall_back_to_page_description(): void
    {
        $checked = 0;

        foreach (C::pages() as $page) {
            foreach (Access::screensOf($page) as $screen) {
                $this->assertSame(C::describe($page), C::describe($screen), $screen);
                $this->assertNotSame($screen, C::describe($screen)['what']);
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_locked_keys_are_described_and_risk_labels_exist(): void
    {
        foreach (['ar', 'en'] as $loc) {
            app()->setLocale($loc);

            foreach (Access::LOCKED as $k) {
                $this->assertNotSame($k, C::describe($k)['what'], "$loc:$k");
            }

            foreach (C::LEVELS as $l) {
                $this->assertNotSame('permref.risk_'.$l, C::riskLabel($l), "$loc:$l");
            }
        }
    }

    public function test_no_orphan_lang_entries(): void
    {
        $valid = array_map(
            fn ($k) => substr(C::langKey($k), 8),
            array_merge(C::groups(), C::pages(), C::actions(), Access::LOCKED)
        );

        foreach (['ar', 'en'] as $loc) {
            $arr = require base_path("lang/$loc/permref.php");

            foreach (array_keys($arr) as $k) {
                if (str_starts_with($k, 'risk_')) {
                    continue;
                }

                $this->assertContains($k, $valid, "orphan $loc:$k");
            }
        }
    }
}
