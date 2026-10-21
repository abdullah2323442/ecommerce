<?php
// QA only: router for the second test server. It moves the clock forward (QA_HOURS), then hands over to Laravel's own router.
require_once __DIR__.'/clock.php';

return require __DIR__.'/../../../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
