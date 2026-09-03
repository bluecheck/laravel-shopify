<?php

namespace Osiset\ShopifyApp\Test\Stubs;

class OfflineTokenInterceptorStub
{
    public static $wasCalled = false;

    public static $args = [];

    public static function reset(): void
    {
        self::$wasCalled = false;
        self::$args = [];
    }

    public function ensureFreshAccessToken(
        string $shopDomain,
        string $clientId,
        string $clientSecret
    ): ?string {
        self::$wasCalled = true;
        self::$args = compact('shopDomain', 'clientId', 'clientSecret');

        return 'stubbed-token';
    }
}
