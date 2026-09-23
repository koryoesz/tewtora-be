<?php

use App\Domains\Auth\AuthServiceProvider;
use App\Domains\Core\CoreServiceProvider;
use App\Domains\Recommendation\RecommendationServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    RecommendationServiceProvider::class,
    CoreServiceProvider::class,
];
