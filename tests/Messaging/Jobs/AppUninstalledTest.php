<?php

namespace Osiset\ShopifyApp\Test\Messaging\Jobs;

use Osiset\ShopifyApp\Messaging\Jobs\AppUninstalledJob;
use Osiset\ShopifyApp\Objects\Enums\ChargeStatus;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class AppUninstalledTest extends TestCase
{
    public function testJobSoftDeletesShopAndCharges(): void
    {
        // Create a plan
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();

        // Create a shop attached to the plan
        $shop = factory($this->model)->create([
            'plan_id' => $plan->getId()->toNative(),
            'refresh_token' => 'shprt_to_clear',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
        ]);

        // Create a charge for the shop and plan
        factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create([
            'plan_id' => $plan->getId()->toNative(),
            'user_id' => $shop->getId()->toNative(),
            'status' => ChargeStatus::ACTIVE()->toNative(),
        ]);

        // Ensure shop is not trashed, and has charges
        $this->assertFalse($shop->trashed());
        $this->assertTrue($shop->hasCharges());
        $this->assertNotNull($shop->plan);
        $this->assertNotEmpty($shop->password);
        $this->assertNotEmpty($shop->refresh_token);

        // Run the job
        AppUninstalledJob::dispatchNow(
            $shop->getDomain()->toNative(),
            json_decode(file_get_contents(__DIR__.'/../../fixtures/app_uninstalled.json'))
        );

        // Refresh both models to see the changes
        $shop->refresh();

        // Confirm job worked...
        $this->assertTrue($shop->trashed());
        $this->assertFalse($shop->hasCharges());
        $this->assertNull($shop->plan);
        $this->assertEmpty($shop->password);
        $this->assertNull($shop->refresh_token);
        $this->assertNull($shop->access_token_expires_at);
        $this->assertNull($shop->refresh_token_expires_at);
    }
}
