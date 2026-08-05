<?php

use App\Providers\AppServiceProvider;
use App\Providers\ImportingServiceProvider;
use App\Providers\OffersServiceProvider;
use App\Providers\PricingServiceProvider;
use App\Providers\TravelServiceProvider;

return [
    AppServiceProvider::class,
    ImportingServiceProvider::class,
    OffersServiceProvider::class,
    PricingServiceProvider::class,
    TravelServiceProvider::class,
];
