<?php

namespace App\Adapters;

use App\Contracts\EcommercePlatformInterface;
use App\Models\Channel;
use App\Services\IkasService;
use App\Services\TicimaxService;

class EcommercePlatformFactory
{
    public static function create(Channel $channel): EcommercePlatformInterface
    {
        return match (true) {
            str_starts_with($channel->type, 'ticimax') => new TicimaxEcommerceAdapter(
                TicimaxService::fromChannel($channel)
            ),
            str_starts_with($channel->type, 'ikas') => new IkasEcommerceAdapter(
                IkasService::fromChannel($channel)
            ),
            default => throw new \InvalidArgumentException("Unsupported ecommerce platform: {$channel->type}"),
        };
    }
}
