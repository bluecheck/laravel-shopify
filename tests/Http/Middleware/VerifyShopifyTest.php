<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Exceptions\SignatureVerificationException;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Test\Stubs\OfflineTokenInterceptorStub;

class VerifyShopifyTest extends TestCase
{
    public function testHmacFail(): void
    {
        $this->expectException(SignatureVerificationException::class);

        // Setup request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [
                'shop' => 'mystore123.myshopify.com',
                'hmac' => '9f4d79eb5ab1806c390b3dda0bfc7be714a92df165d878f22cf3cc8145249ca8',
                'timestamp' => 'oops',
                'code' => 'oops',
            ],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            []
        );

        // Run the middleware
        $this->runMiddleware(VerifyShopify::class, $newRequest);
    }

    public function testSkipAuthenticateAndBillingRoutes(): void
    {
        // Setup the request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            ['REQUEST_URI' => '/authenticate']
        );

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertTrue($result[0]);
    }

    public function testMissingToken(): void
    {
        // Create a shop
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com']);

        // Setup the request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            ['shop' => $shop->name],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            null
        );

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertFalse($result[0]);
    }

    public function testMissingTokenAjax(): void
    {
        $this->expectException(HttpException::class);

        // Create a shop
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com']);

        // Setup the request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            [
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
                'HTTP_X-Shop-Domain' => $shop->name,
            ]
        );

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertFalse($result[0]);
    }

    public function testTokenProcessingAndLoginShop(): void
    {
        // Create a shop that matches the token from buildToken
        factory($this->model)->create(['name' => 'shop-name.myshopify.com']);

        // Setup the request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            [
                'HTTP_Authorization' => "Bearer {$this->buildToken()}",
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertTrue($result[0]);
    }

    public function testExpiredRefreshTokenTriggersOfflineTokenInterceptor(): void
    {
        $this->ensureExpiringOfflineColumns();

        factory($this->model)->create([
            'name' => 'shop-name.myshopify.com',
            'refresh_token' => 'refresh-token',
            'refresh_token_expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->app['config']->set('shopify-app.offline_token_interceptor', OfflineTokenInterceptorStub::class);
        $this->app['config']->set('shopify-app.api_expiring_offline_tokens', true);
        OfflineTokenInterceptorStub::reset();

        $newRequest = $this->createAjaxRequestWithToken();
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);

        $this->assertTrue($result[0]);
        $this->assertTrue(OfflineTokenInterceptorStub::$wasCalled);
        $this->assertTrue(OfflineTokenInterceptorStub::$force);
    }

    public function testRefreshTokenExpiringWithinUrgentWindowTriggersInterceptor(): void
    {
        $this->ensureExpiringOfflineColumns();

        factory($this->model)->create([
            'name' => 'shop-name.myshopify.com',
            'refresh_token' => 'refresh-token',
            'refresh_token_expires_at' => Carbon::now()->addDays(2),
        ]);

        $this->app['config']->set('shopify-app.offline_token_interceptor', OfflineTokenInterceptorStub::class);
        $this->app['config']->set('shopify-app.api_expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_token_refresh_before_days', 3);
        $this->app['config']->set('shopify-app.offline_token_refresh_before_extra_hours', 6);
        OfflineTokenInterceptorStub::reset();

        $newRequest = $this->createAjaxRequestWithToken();
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);

        $this->assertTrue($result[0]);
        $this->assertTrue(OfflineTokenInterceptorStub::$wasCalled);
        $this->assertTrue(OfflineTokenInterceptorStub::$force);
    }

    public function testRefreshTokenExpiringBeyondUrgentWindowSkipsInterceptor(): void
    {
        $this->ensureExpiringOfflineColumns();

        factory($this->model)->create([
            'name' => 'shop-name.myshopify.com',
            'refresh_token' => 'refresh-token',
            'refresh_token_expires_at' => Carbon::now()->addDays(10),
        ]);

        $this->app['config']->set('shopify-app.offline_token_interceptor', OfflineTokenInterceptorStub::class);
        $this->app['config']->set('shopify-app.api_expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_token_refresh_before_days', 3);
        $this->app['config']->set('shopify-app.offline_token_refresh_before_extra_hours', 6);
        OfflineTokenInterceptorStub::reset();

        $newRequest = $this->createAjaxRequestWithToken();
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);

        $this->assertTrue($result[0]);
        $this->assertFalse(OfflineTokenInterceptorStub::$wasCalled);
    }

    public function testRefreshTokenExpiringWithinOneDayTriggersInterceptor(): void
    {
        $this->ensureExpiringOfflineColumns();

        factory($this->model)->create([
            'name' => 'shop-name.myshopify.com',
            'refresh_token' => 'refresh-token',
            'refresh_token_expires_at' => Carbon::now()->addDay(),
        ]);

        $this->app['config']->set('shopify-app.offline_token_interceptor', OfflineTokenInterceptorStub::class);
        $this->app['config']->set('shopify-app.api_expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_token_refresh_before_days', 3);
        $this->app['config']->set('shopify-app.offline_token_refresh_before_extra_hours', 6);
        OfflineTokenInterceptorStub::reset();

        $newRequest = $this->createAjaxRequestWithToken();
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);

        $this->assertTrue($result[0]);
        $this->assertTrue(OfflineTokenInterceptorStub::$wasCalled);
        $this->assertTrue(OfflineTokenInterceptorStub::$force);
    }

    public function testApiExpiringOfflineTokensDisabledSkipsInterceptor(): void
    {
        $this->ensureExpiringOfflineColumns();

        factory($this->model)->create([
            'name' => 'shop-name.myshopify.com',
            'refresh_token' => 'refresh-token',
            'refresh_token_expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->app['config']->set('shopify-app.offline_token_interceptor', OfflineTokenInterceptorStub::class);
        $this->app['config']->set('shopify-app.api_expiring_offline_tokens', false);
        OfflineTokenInterceptorStub::reset();

        $newRequest = $this->createAjaxRequestWithToken();
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);

        $this->assertTrue($result[0]);
        $this->assertFalse(OfflineTokenInterceptorStub::$wasCalled);
    }

    public function testMissingOfflineTokenInterceptorSkipsExecution(): void
    {
        $this->ensureExpiringOfflineColumns();

        factory($this->model)->create([
            'name' => 'shop-name.myshopify.com',
            'refresh_token' => 'refresh-token',
            'refresh_token_expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->app['config']->set('shopify-app.offline_token_interceptor', null);
        $this->app['config']->set('shopify-app.api_expiring_offline_tokens', true);
        OfflineTokenInterceptorStub::reset();

        $newRequest = $this->createAjaxRequestWithToken();
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);

        $this->assertTrue($result[0]);
        $this->assertFalse(OfflineTokenInterceptorStub::$wasCalled);
    }

    public function testTokenProcessingAndNotInstalledShop(): void
    {
        // Setup the request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [
                'token' => $this->buildToken(),
                'shop' => 'non-existent.myshopify.com',
            ],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            []
        );

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertFalse($result[0]);
    }

    public function testTokenProcessingAndNotInstalledShopAjax(): void
    {
        $this->expectException(HttpException::class);

        // Setup the request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            [
                'HTTP_Authorization' => "Bearer {$this->buildToken()}",
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertFalse($result);
    }

    public function testInvalidToken(): void
    {
        // Setup the request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            ['token' => $this->buildToken().'OOPS'],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            []
        );

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertFalse($result[0]);
    }

    public function testInvalidTokenAjax(): void
    {
        $this->expectException(HttpException::class);

        // Setup the request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            [
                'HTTP_Authorization' => "Bearer {$this->buildToken()}OOPS",
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertFalse($result[0]);
    }

    public function testTokenProcessingAndMissMatchingShops(): void
    {
        // Create a shop that matches the token from buildToken
        factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        factory($this->model)->create(['name' => 'some-other-shop.myshopify.com']);

        // Setup the request
        $token = $this->buildToken();
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            [
                'HTTP_Authorization' => "Bearer {$token}",
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]
        );
        Request::swap($newRequest);

        // Run the middleware
        $result = $this->runMiddleware(VerifyShopify::class, $newRequest);
        $this->assertTrue($result[0]);

        // Run the middleware and change the shop
        $token = $this->buildToken(['dest' => 'https://some-other-shop.myshopify.com', 'iss' => 'https://some-other-shop.myshopify.com/admin']);
        $newRequest = $newRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            [
                'HTTP_Authorization' => "Bearer {$token}",
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        $this->expectException(HttpException::class);
        $this->runMiddleware(VerifyShopify::class, $newRequest);
    }

    protected function createAjaxRequestWithToken()
    {
        $currentRequest = Request::instance();

        return $currentRequest->duplicate(
            [],
            null,
            null,
            null,
            null,
            [
                'HTTP_Authorization' => "Bearer {$this->buildToken()}",
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]
        );
    }

    protected function ensureExpiringOfflineColumns(): void
    {
        if (Schema::hasColumn('users', 'refresh_token')
            && Schema::hasColumn('users', 'access_token_expires_at')
            && Schema::hasColumn('users', 'refresh_token_expires_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'refresh_token')) {
                $table->string('refresh_token')->nullable();
            }

            if (! Schema::hasColumn('users', 'access_token_expires_at')) {
                $table->timestamp('access_token_expires_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'refresh_token_expires_at')) {
                $table->timestamp('refresh_token_expires_at')->nullable();
            }
        });
    }
}
