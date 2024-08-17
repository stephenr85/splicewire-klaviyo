<?php

namespace Splicewire\Klaviyo;

use Illuminate\Support\ServiceProvider;
use Splicewire\Klaviyo\Commands\SyncCampaignTemplates;

class PackageServiceProvider extends ServiceProvider {


    public function boot()
    {
       $this->publishes([
            __DIR__ . '/../config/klaviyo.php' => config_path('splicewire/klaviyo.php'),
       ]);

       $this->commands([
            SyncCampaignTemplates::class,
        ]);
    }

    public function register()
    {

    }
}
