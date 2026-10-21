<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * «الدليل» جوه الـERP (٢١/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * موقع التسليم الساكن بيتخدم من فولدر بره `public` ورا اللوجين —
 * الصور فيها أسامي عملاء حقيقية، فمفيش ولا ملف يتفتح من غير جلسة.
 *
 * ⚠️ التيستات بتشتغل على فولدر مؤقت (`config(['docs.path'])`) —
 * عمرها ما تقرا `storage/app/docs` الحقيقي.
 */
class DocsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'promax_docs_'.uniqid();
        File::makeDirectory($this->root.'/assets', 0755, true);
        File::makeDirectory($this->root.'/media/shots', 0755, true);
        File::put($this->root.'/index.html', '<!doctype html><title>DOCS-INDEX</title><link rel="stylesheet" href="assets/app.css">');
        File::put($this->root.'/assets/app.css', 'body{color:red}');
        File::put($this->root.'/assets/app.js', 'console.log(1)');
        File::put($this->root.'/media/shots/S041.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        File::put($this->root.'/media/clip.mp4', str_repeat('0123456789', 100));
        File::put($this->root.'/secret.php', '<?php echo 1;');
        File::put($this->root.'/notes.json', '{}');
        // ملف حساس «جنب» الفولدر — هدف محاولات الخروج
        File::put(dirname($this->root).'/promax_docs_outside.txt', 'OUTSIDE');

        config(['docs.path' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        @unlink(dirname($this->root).'/promax_docs_outside.txt');

        parent::tearDown();
    }

    private function user(string $role): User
    {
        return $this->makeAdmin(['role' => $role]);
    }

    // ═══════════════ الدخول ═══════════════

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/docs')->assertRedirect(route('login'));
        $this->get('/docs/index.html')->assertRedirect(route('login'));
        $this->get('/docs/media/shots/S041.png')->assertRedirect(route('login'));
    }

    public function test_docs_opens_on_its_index_page_so_relative_paths_resolve(): void
    {
        // ⚠️ مش `/docs/` — لارافيل وhtaccess بيشيلوا السلاش الأخيرة،
        // فـ`assets/app.css` كانت هتتحل لـ`/assets/app.css` بره الدليل
        $this->actingAs($this->user('accountant'))
            ->get('/docs')
            ->assertRedirect('/docs/index.html');
    }

    /** @dataProvider docsRoles */
    public function test_every_web_role_with_the_book_reads_the_index(string $role): void
    {
        $res = $this->actingAs($this->user($role))->get('/docs/index.html');

        $res->assertOk();
        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('DOCS-INDEX', $this->body($res));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public static function docsRoles(): array
    {
        return [
            'admin' => ['admin'], 'manager' => ['manager'], 'branch_manager' => ['branch_manager'],
            'accountant' => ['accountant'], 'warehouse_keeper' => ['warehouse_keeper'],
            'production_manager' => ['production_manager'], 'quality' => ['quality'],
            'rnd' => ['rnd'], 'hr' => ['hr'],
        ];
    }

    public function test_the_handover_reviewer_and_field_roles_do_not_get_the_book(): void
    {
        $this->actingAs($this->user('handover_reviewer'))->get('/docs/index.html')->assertForbidden();
        $this->actingAs($this->makeRep())->get('/docs/index.html')->assertForbidden();
    }

    // ═══════════════ الملفات ═══════════════

    public function test_assets_come_back_with_the_right_type_and_cache_headers(): void
    {
        $u = $this->user('manager');

        $css = $this->actingAs($u)->get('/docs/assets/app.css');
        $css->assertOk();
        $this->assertStringContainsString('text/css', $css->headers->get('Content-Type'));

        $js = $this->actingAs($u)->get('/docs/assets/app.js');
        $js->assertOk();
        $this->assertStringContainsString('javascript', $js->headers->get('Content-Type'));

        $png = $this->actingAs($u)->get('/docs/media/shots/S041.png');
        $png->assertOk();
        $this->assertSame('image/png', $png->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=', $png->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $png->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $png->headers->get('X-Content-Type-Options'));
    }

    public function test_video_supports_http_range(): void
    {
        $res = $this->actingAs($this->user('manager'))
            ->get('/docs/media/clip.mp4', ['Range' => 'bytes=0-9']);

        $res->assertStatus(206);
        $this->assertSame('video/mp4', $res->headers->get('Content-Type'));
        $this->assertSame('bytes 0-9/1000', $res->headers->get('Content-Range'));
        $this->assertSame('0123456789', $this->body($res));
    }

    public function test_a_missing_file_is_404(): void
    {
        $this->actingAs($this->user('manager'))->get('/docs/assets/nope.css')->assertNotFound();
    }

    public function test_disallowed_extensions_are_404(): void
    {
        $u = $this->user('manager');
        $this->actingAs($u)->get('/docs/secret.php')->assertNotFound();
        $this->actingAs($u)->get('/docs/notes.json')->assertNotFound();
    }

    public function test_path_traversal_never_leaves_the_docs_root(): void
    {
        $u = $this->user('admin');

        foreach ([
            '/docs/../promax_docs_outside.txt',
            '/docs/assets/../../promax_docs_outside.txt',
            '/docs/..%2fpromax_docs_outside.txt',
            '/docs/assets/..%2f..%2fpromax_docs_outside.txt',
            '/docs/%2e%2e/promax_docs_outside.txt',
            '/docs/..%5cpromax_docs_outside.txt',
            '/docs/'.ltrim(str_replace('\\', '/', dirname($this->root).'/promax_docs_outside.txt'), '/'),
            '/docs/C:/Windows/win.ini',
            '/docs/../.env',
            '/docs/..%2f..%2f.env',
        ] as $path) {
            $res = $this->actingAs($u)->get($path);
            $this->assertSame(404, $res->getStatusCode(),
                'traversal answered '.$res->getStatusCode().' for '.$path);
            $this->assertStringNotContainsString('OUTSIDE', (string) $res->getContent(), $path);
            $this->assertStringNotContainsString('APP_KEY', (string) $res->getContent(), $path);
        }
    }

    // ═══════════════ الفولدر لسه ما اترفعش ═══════════════

    public function test_missing_folder_shows_a_friendly_page_not_a_500(): void
    {
        config(['docs.path' => $this->root.'_missing']);
        $u = $this->user('accountant');

        $res = $this->actingAs($u)->get('/docs/index.html');
        $res->assertOk();
        $res->assertSee(__('nav.docs_missing_title'));

        $this->actingAs($u)->get('/docs')->assertOk()->assertSee(__('nav.docs_missing_title'));
        $this->actingAs($u)->get('/docs/assets/app.css')->assertNotFound();
    }

    // ═══════════════ المنيو ═══════════════

    public function test_the_book_is_in_the_sidebar_for_every_web_role_but_the_reviewer(): void
    {
        foreach (array_keys(self::docsRoles()) as $role) {
            $this->assertTrue(Access::allows($this->user($role), 'docs.index'), $role);
            $nav = collect(Access::navFor($this->user($role)))->flatten(1)->pluck(0)->all();
            $this->assertContains('docs.index', $nav, $role);
        }

        $this->assertFalse(Access::allows($this->user('handover_reviewer'), 'docs.index'));
    }

    public function test_the_sidebar_link_is_rendered(): void
    {
        $this->actingAs($this->user('warehouse_keeper'))
            ->get(route('wh.index'))
            ->assertOk()
            ->assertSee(route('docs.index'), false)
            ->assertSee(__('nav.docs'));
    }

    private function body($res): string
    {
        $base = $res->baseResponse;

        if ($base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            ob_start();
            $base->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $res->getContent();
    }
}
