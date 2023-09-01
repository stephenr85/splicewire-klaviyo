<?php

namespace SpearGen\Klaviyo;

use Illuminate\Support\ServiceProvider;
use SpearGen\Klaviyo\Commands\SyncCampaignTemplates;

class PackageServiceProvider extends ServiceProvider {


    public function boot()
    {
       $this->publishes([
            __DIR__ . '/../config/klaviyo.php' => config_path('speargen/klaviyo.php'),
       ]);

       $this->commands([
            SyncCampaignTemplates::class,
        ]);
    }

    public function register()
    {

    }
}
