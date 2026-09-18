<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Osiset\ShopifyApp\Actions\InstallShop;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class InstallShopTest extends TestCase
{
    /**
     * @var \Osiset\ShopifyApp\Actions\InstallShop
     */
    protected $action;

    public function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(InstallShop::class);
    }

    public function testNoShopShouldBeMade(): void
    {
        $result = call_user_func(
            $this->action,
            ShopDomain::fromNative('non-existant.myshopify.com'),
            null
        );

        $this->assertStringContainsString(
            '/admin/oauth/authorize?client_id='.Util::getShopifyConfig('api_key').'&scope=read_products%2Cwrite_products&redirect_uri=https%3A%2F%2Flocalhost%2Fauthenticate',
            $result['url']
        );
        $this->assertFalse($result['completed']);
        $this->assertNotNull($result['shop_id']);
    }

    public function testWithoutCode(): void
    {
        // Create the shop
        $shop = factory($this->model)->create();

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            null
        );

        $this->assertStringContainsString(
            '/admin/oauth/authorize?client_id='.Util::getShopifyConfig('api_key').'&scope=read_products%2Cwrite_products&redirect_uri=https%3A%2F%2Flocalhost%2Fauthenticate',
            $result['url']
        );
        $this->assertFalse($result['completed']);
        $this->assertNotNull($result['shop_id']);
    }

    public function testWithCode(): void
    {
        // Create the shop
        $shop = factory($this->model)->create();

        // Get the current access token
        $currentToken = $shop->getAccessToken();

        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            '12345678'
        );

        // Refresh to see changes
        $shop->refresh();

        $this->assertTrue($result['completed']);
        $this->assertNotNull($result['shop_id']);
        $this->assertNotSame($currentToken->toNative(), $shop->getAccessToken()->toNative());
        $this->assertSame('shpat_expiring_access_token_123', $shop->password);
        $this->assertSame('shprt_expiring_refresh_token_456', $shop->refresh_token);
        $this->assertNotNull($shop->access_token_expires_at);
        $this->assertNotNull($shop->refresh_token_expires_at);
        $this->assertEqualsWithDelta(
            $this->now->addSeconds(3600)->getTimestamp(),
            $shop->access_token_expires_at->getTimestamp(),
            2
        );
        $this->assertEqualsWithDelta(
            $this->now->addSeconds(7776000)->getTimestamp(),
            $shop->refresh_token_expires_at->getTimestamp(),
            2
        );
    }

    public function testWithCodeSoftDeletedShop(): void
    {
        // Create the shop
        $shop = factory($this->model)->create([
            'deleted_at' => $this->now->getTimestamp(),
        ]);

        // Get the current access token
        $currentToken = $shop->getAccessToken();

        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            '12345678'
        );

        // Refresh to see changes
        $shop->refresh();

        $this->assertTrue($result['completed']);
        $this->assertNotNull($result['shop_id']);
        $this->assertNotSame($currentToken->toNative(), $shop->getAccessToken()->toNative());
        $this->assertSame('shprt_expiring_refresh_token_456', $shop->refresh_token);
    }

    public function testWithCodeLegacyNonExpiring(): void
    {
        $this->app['config']->set('shopify-app.api_expiring_offline_tokens', false);

        $shop = factory($this->model)->create();
        $currentToken = $shop->getAccessToken();

        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            '12345678'
        );

        $shop->refresh();

        $this->assertTrue($result['completed']);
        $this->assertNotSame($currentToken->toNative(), $shop->getAccessToken()->toNative());
        $this->assertSame('12345678', $shop->password);
        $this->assertNull($shop->refresh_token);
        $this->assertNull($shop->access_token_expires_at);
        $this->assertNull($shop->refresh_token_expires_at);
    }
}
