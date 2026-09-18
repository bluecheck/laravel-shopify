<?php

namespace Osiset\ShopifyApp\Test\Stubs;

class OfflineTokenInterceptorStub
{
    public static $wasCalled = false;

    public static $args = [];

    public static $force = false;

    public static function reset(): void
    {
        self::$wasCalled = false;
        self::$args = [];
        self::$force = false;
    }

    public function ensureFreshAccessToken(
        string $shopDomain,
        string $clientId,
        string $clientSecret,
        int $skewSeconds = 30,
        bool $force = false
    ): ?string {
        self::$wasCalled = true;
        self::$force = $force;
        self::$args = compact('shopDomain', 'clientId', 'clientSecret', 'skewSeconds', 'force');

        return 'stubbed-token';
    }
}
