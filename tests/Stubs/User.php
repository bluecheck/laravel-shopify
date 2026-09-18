<?php

namespace Osiset\ShopifyApp\Test\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Traits\ShopModel;

class User extends Authenticatable implements IShopModel
{
    use Notifiable;
    use ShopModel;

    protected $fillable = [
        'name', 'email', 'password', 'refresh_token',
        'access_token_expires_at', 'refresh_token_expires_at',
    ];

    protected $hidden = [
        'password', 'refresh_token', 'remember_token',
    ];

    protected $casts = [
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
    ];
}
