<?php

namespace Tests\Feature\Saas;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SignupAndBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    public function test_signup_creates_tenant_owner_and_trial(): void
    {
        $res = $this->postJson('/api/public/signup', [
            'company' => 'Deneme Mağaza', 'name' => 'Ayşe Yılmaz', 'email' => 'ayse@example.com',
            'password' => 'guclu-sifre-123', 'terms' => true, 'platform' => 'ticimax',
        ]);
        $res->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email', 'allowed_products']]);

        $tenant = Tenant::where('slug', 'deneme-magaza')->firstOrFail();
        $this->assertTrue(User::where('tenant_id', $tenant->id)->where('is_owner', true)->exists());
        $sub = Subscription::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('trialing', $sub->status);
        $this->assertNotEmpty($tenant->fresh()->settings['products']);
        $this->assertTrue($sub->grantsAccess());
    }

    public function test_duplicate_owner_email_rejected(): void
    {
        $payload = ['company' => 'Firma A', 'name' => 'A B', 'email' => 'dup@example.com', 'password' => 'guclu-sifre-123', 'terms' => true];
        $this->postJson('/api/public/signup', $payload)->assertCreated();
        $this->postJson('/api/public/signup', $payload + ['company' => 'Firma B'])->assertStatus(422);
    }

    public function test_expired_trial_blocks_api_but_allows_billing(): void
    {
        $this->postJson('/api/public/signup', ['company' => 'Firma X', 'name' => 'X Y', 'email' => 'x@example.com', 'password' => 'guclu-sifre-123', 'terms' => true])->assertCreated();
        $tenant = Tenant::where('slug', 'firma-x')->firstOrFail();
        $sub = $tenant->subscription;
        $sub->forceFill(['trial_ends_at' => now()->subDay()])->save();
        app(SubscriptionManager::class)->renewDue();
        $this->assertSame('expired', $sub->fresh()->status);

        $user = $tenant->users()->first();
        $this->actingAs($user)->getJson('/api/settings')->assertStatus(402)->assertJsonPath('code', 'subscription_required');
        $this->actingAs($user)->getJson('/api/billing/overview')->assertOk();
    }

    public function test_manual_activation_unlocks_and_applies_plan(): void
    {
        $this->postJson('/api/public/signup', ['company' => 'Firma Z', 'name' => 'Z Y', 'email' => 'z@example.com', 'password' => 'guclu-sifre-123', 'terms' => true])->assertCreated();
        $tenant = Tenant::where('slug', 'firma-z')->firstOrFail();
        $plan = Plan::where('family', config('saas.family'))->orderByDesc('price_monthly')->first();
        app(SubscriptionManager::class)->manualActivate($tenant, $plan, 'monthly', 30);
        $this->assertSame('active', $tenant->subscription->fresh()->status);
        $this->assertSame($plan->products, $tenant->fresh()->settings['products']);
    }

    public function test_invoice_numbers_are_sequential(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'is_active' => true, 'settings' => []]);
        $m = app(SubscriptionManager::class);
        $sub = $m->startTrial($tenant);
        $plan = $sub->plan;
        $a = $m->createInvoice($tenant, $sub, $plan, 'monthly', now(), now()->addMonth(), 'manual');
        $b = $m->createInvoice($tenant, $sub, $plan, 'monthly', now(), now()->addMonth(), 'manual');
        $this->assertSame((int) substr($a->number, -6) + 1, (int) substr($b->number, -6));
        $this->assertEquals(round($a->subtotal * 1.2, 2), (float) $a->total);
        $this->assertSame(Invoice::OPEN, $a->status);
    }
}
