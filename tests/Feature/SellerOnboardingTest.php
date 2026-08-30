<?php

namespace Tests\Feature;

use App\Models\SellerOnboarding;
use App\Services\SellerOnboardingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Tests\TestCase;

/** Run against a migrated, disposable local database; every test rolls back. */
class SellerOnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!preg_match('/(_onboarding|_test|testing)$/', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('Use a dedicated local test database.');
        }
        DB::beginTransaction();
        config(['seller_onboarding.publication_policy' => 'post_moderation']);
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function seller(): User
    {
        $user = User::create(['name' => 'Local Seller', 'email' => Str::uuid() . '@example.test', 'password' => bcrypt('LocalTest!2026'), 'is_active' => true]);
        $user->givePermissionTo(['customer', 'store_owner']);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function payload(): array
    {
        $state = $this->getJson('/api/seller/onboarding')->assertOk()->json();
        $this->patchJson('/api/seller/onboarding/shop', ['name' => 'Керамика тест'])->assertOk();
        $image = $this->post('/api/seller/onboarding/photo', ['attachment' => [UploadedFile::fake()->image('vase.jpg', 300, 300)]], ['Accept' => 'application/json'])->assertOk()->json('0');
        return ['request_key' => $state['product_request_key'], 'name' => 'Керамическая ваза', 'price' => '4500,25',
            'category_id' => Category::firstOrFail()->id, 'image' => $image];
    }

    public function test_registration_creates_one_shop_in_same_transaction(): void
    {
        $email = Str::uuid() . '@example.test';
        $this->postJson('/register', ['email' => $email, 'password' => 'LocalTest!2026', 'permission' => 'store_owner',
            'accept_terms' => true, 'accept_privacy' => true, 'attribution' => ['utm_source' => 'yandex', 'yclid' => 'local-test']])->assertOk()->assertJsonStructure(['token']);
        $user = User::where('email', $email)->firstOrFail();
        $state = app(SellerOnboardingService::class)->ensure($user);
        $this->assertSame(1, Shop::where('owner_id', $user->id)->count());
        $this->assertSame('shop', $state->step);
        $this->assertSame('yandex', $state->attribution['utm_source']);
        $this->assertSame(0, Product::where('shop_id', $state->shop_id)->count());
    }

    public function test_resume_reuses_shop_and_persists_product_draft(): void
    {
        $user = $this->seller();
        $state = $this->getJson('/api/seller/onboarding')->assertOk()->json();
        $this->patchJson('/api/seller/onboarding/shop', ['name' => 'Мастерская'])->assertOk()->assertJsonPath('step', 'product');
        $this->patchJson('/api/seller/onboarding/draft', ['version' => 0, 'draft' => ['name' => 'Сохранённая ваза', 'price' => '900']])->assertOk();
        $this->postJson('/api/seller/onboarding/resume')->assertOk()->assertJsonPath('step', 'product')->assertJsonPath('draft.name', 'Сохранённая ваза');
        $this->patchJson('/api/seller/onboarding/draft', ['version' => 0, 'draft' => ['name' => 'Устаревшая вкладка']])->assertStatus(409);
        $this->assertSame(1, Shop::where('owner_id', $user->id)->count());
        $this->getJson('/api/seller/onboarding')->assertJsonPath('shop.id', $state['shop']['id']);
    }

    public function test_legacy_manually_created_shop_is_reused_even_if_disabled(): void
    {
        $user = $this->seller();
        $shop = Shop::create(['owner_id' => $user->id, 'name' => 'Ручной магазин', 'is_active' => false]);
        $this->getJson('/api/seller/onboarding')->assertOk()->assertJsonPath('step', 'product')->assertJsonPath('shop.id', $shop->id)->assertJsonPath('shop.is_active', false);
        $this->assertSame(1, Shop::where('owner_id', $user->id)->count());
    }

    public function test_first_product_is_published_once_and_conversion_is_deduplicated(): void
    {
        $user = $this->seller();
        $payload = $this->payload();
        $first = $this->postJson('/api/seller/onboarding/product', $payload)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('product.visible', true)->json();
        $this->postJson('/api/seller/onboarding/product', $payload)->assertOk()->assertJsonPath('product.id', $first['product']['id']);
        $this->getJson('/api/seller/onboarding')->assertJsonPath('status', 'completed');
        $this->assertSame(1, Product::where('shop_id', $first['shop']['id'])->count());
        $product = Product::findOrFail($first['product']['id']);
        $this->assertSame('pending', $product->moderation_status);
        $this->assertEquals(4500.25, $product->price);
        $this->assertEmpty($product->description);
        // The full editor sends an empty optional seller SKU as null.
        $this->actingAs($user, 'api')->withToken($user->createToken('local-test')->plainTextToken);
        $this->putJson('/products/' . $product->id, ['name' => $product->name, 'price' => '4500.25', 'sku' => null, 'description' => null, 'status' => 'publish'])->assertOk();
        $this->assertSame(1, DB::table('seller_onboarding_events')->where('user_id', $user->id)->where('event', 'seller_first_product_published')->count());
        $events = $this->postJson('/api/seller/onboarding/events/claim')->assertOk()->json();
        $this->assertNotEmpty($events);
        $this->assertSame(['event'], array_keys($events[0]));
        $this->postJson('/api/seller/onboarding/events/claim')->assertOk()->assertExactJson([]);
    }

    public function test_review_policy_keeps_activation_incomplete_until_admin_approval(): void
    {
        $user = $this->seller();
        config(['seller_onboarding.publication_policy' => 'all']);
        $response = $this->postJson('/api/seller/onboarding/product', $this->payload())->assertOk()->assertJsonPath('status', 'in_progress')->assertJsonPath('product.visible', false)->json();
        $this->assertFalse(DB::table('seller_onboarding_events')->where('user_id', $user->id)->where('event', 'seller_first_product_published')->exists());
        $admin = User::create(['name' => 'Test admin', 'email' => Str::uuid() . '@example.test', 'is_active' => true]);
        $admin->givePermissionTo('super_admin');
        $this->actingAs($admin, 'api')->withToken($admin->createToken('local-test')->plainTextToken);
        $this->putJson('/products/' . $response['product']['id'], ['name' => 'Керамическая ваза', 'status' => 'approved', 'product_type' => 'simple'])->assertOk();
        $this->assertSame('publish', Product::findOrFail($response['product']['id'])->status);
        $this->assertNotNull(SellerOnboarding::where('user_id', $user->id)->first()->completed_at);
    }

    public function test_rejection_cannot_be_undone_by_seller_edit_or_second_submit(): void
    {
        $user = $this->seller();
        $data = $this->postJson('/api/seller/onboarding/product', $this->payload())->assertOk()->json();
        $admin = User::create(['name' => 'Test admin', 'email' => Str::uuid() . '@example.test', 'is_active' => true]);
        $admin->givePermissionTo('super_admin');
        $this->actingAs($admin, 'api')->withToken($admin->createToken('local-test')->plainTextToken);
        $path = '/products/' . $data['product']['id'];
        $this->putJson($path, ['name' => 'Ваза', 'status' => 'rejected', 'product_type' => 'simple'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'api')->withToken($user->createToken('local-test')->plainTextToken);
        for ($i = 0; $i < 2; $i++) {
            $response = $this->putJson($path, ['name' => 'Исправленная ваза', 'status' => 'publish', 'product_type' => 'simple']);
            $this->assertSame(200, $response->status(), $response->getContent());
            $this->assertNotSame('publish', Product::findOrFail($data['product']['id'])->status);
        }
    }

    public function test_validation_and_wrong_idempotency_key_create_no_product(): void
    {
        $user = $this->seller();
        $payload = $this->payload();
        $this->postJson('/api/seller/onboarding/product', array_merge($payload, ['price' => '-1']))->assertStatus(422);
        $this->postJson('/api/seller/onboarding/product', array_merge($payload, ['request_key' => 'wrong']))->assertStatus(409);
        $state = SellerOnboarding::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, Product::where('shop_id', $state->shop_id)->count());
        $this->assertNull($state->completed_at);
    }

    public function test_skip_does_not_complete_onboarding(): void
    {
        $this->seller();
        $this->postJson('/api/seller/onboarding/skip')->assertOk();
        $this->postJson('/api/seller/onboarding/resume')->assertOk()->assertJsonPath('status', 'in_progress')->assertJsonPath('step', 'shop');
    }

    public function test_old_seller_login_repairs_missing_shop_and_resumes_existing_draft(): void
    {
        $user = $this->seller();
        $this->postJson('/token', ['email' => $user->email, 'password' => 'LocalTest!2026'])->assertOk()->assertJsonStructure(['token']);
        $this->assertSame(1, Shop::where('owner_id', $user->id)->count());
        $this->patchJson('/api/seller/onboarding/shop', ['name' => 'Магазин после входа'])->assertOk();
        $this->patchJson('/api/seller/onboarding/draft', ['version' => 0, 'draft' => ['name' => 'Не потерять', 'price' => '500']])->assertOk();
        $this->postJson('/token', ['email' => $user->email, 'password' => 'LocalTest!2026'])->assertOk();
        $this->getJson('/api/seller/onboarding')->assertOk()->assertJsonPath('step', 'product')->assertJsonPath('draft.name', 'Не потерять');
        $this->assertSame(1, Shop::where('owner_id', $user->id)->count());
    }

    public function test_legacy_seller_with_products_opens_cabinet_without_activation_events(): void
    {
        $user = $this->seller();
        $shop = Shop::create(['owner_id' => $user->id, 'name' => 'Старый магазин', 'is_active' => true]);
        Product::withoutEvents(fn () => Product::create(['shop_id' => $shop->id, 'name' => 'Ранее созданный товар', 'price' => 100, 'quantity' => 1, 'status' => 'draft', 'product_type' => 'simple', 'type_id' => \Marvel\Database\Models\Type::firstOrFail()->id]));
        $this->getJson('/api/seller/onboarding')->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('shop.id', $shop->id);
        $this->assertSame(0, DB::table('seller_onboarding_events')->where('user_id', $user->id)->count());
        $this->assertSame(1, Shop::where('owner_id', $user->id)->count());
    }

    public function test_publication_policy_off_and_new_sellers(): void
    {
        $this->seller();
        config(['seller_onboarding.publication_policy' => 'off']);
        $this->postJson('/api/seller/onboarding/product', $this->payload())->assertOk()->assertJsonPath('product.visible', true)->assertJsonPath('product.moderation_status', 'not_required');
        $this->seller();
        config(['seller_onboarding.publication_policy' => 'new_sellers']);
        $this->postJson('/api/seller/onboarding/product', $this->payload())->assertOk()->assertJsonPath('status', 'in_progress')->assertJsonPath('product.visible', false);
    }

    public function test_another_sellers_photo_cannot_be_attached(): void
    {
        $this->seller();
        $first = $this->payload();
        $secondSeller = $this->seller();
        $second = $this->payload();
        $this->postJson('/api/seller/onboarding/product', array_merge($second, ['image' => $first['image']]))->assertStatus(422);
        $this->assertSame(0, Product::whereIn('shop_id', Shop::where('owner_id', $secondSeller->id)->select('id'))->count());
    }

    public function test_rejected_product_is_private_and_other_seller_cannot_edit_it(): void
    {
        $owner = $this->seller();
        $data = $this->postJson('/api/seller/onboarding/product', $this->payload())->assertOk()->json();
        $product = Product::findOrFail($data['product']['id']);
        Product::withoutEvents(fn () => $product->update(['status' => 'rejected', 'moderation_status' => 'rejected', 'is_published' => false]));
        $detail = '/products/' . $product->full_slug . '?language=' . $product->language;
        $this->getJson($detail)->assertOk();
        $other = $this->seller();
        $this->app['auth']->forgetGuards();
        $this->actingAs($other, 'api')->withToken($other->createToken('local-test')->plainTextToken);
        $this->getJson($detail)->assertStatus(404);
        $this->putJson('/products/' . $product->id, ['status' => 'publish'])->assertStatus(403);
        $otherState = app(SellerOnboardingService::class)->ensure($other);
        $this->putJson('/products/' . $product->id, ['status' => 'publish', 'shop_id' => $otherState->shop_id])->assertStatus(403);
        $this->postJson('/api/products/' . $product->id . '/publish')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        $this->getJson($detail)->assertStatus(404);
    }

    public function test_admin_impersonation_does_not_create_artificial_activation(): void
    {
        $user = $this->seller();
        $payload = $this->payload();
        $token = $user->createToken('admin-impersonation');
        $user->withAccessToken($token->accessToken);
        $this->postJson('/api/seller/onboarding/product', $payload)->assertStatus(403);
        $this->assertFalse(DB::table('seller_onboarding_events')->where('user_id', $user->id)->where('event', 'seller_first_product_published')->exists());
        $this->assertSame(0, Product::whereIn('shop_id', Shop::where('owner_id', $user->id)->select('id'))->count());
    }
}
