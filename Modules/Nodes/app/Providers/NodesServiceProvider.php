<?php

namespace Modules\Nodes\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class NodesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Nodes';

    protected string $nameLower = 'nodes';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
