<?php
// QA only: pushes the clock forward for a second test server, so features that need time to pass can be shown.
// Used as `php -d auto_prepend_file=clock.php -S ...` with QA_HOURS set. It never touches the application code.
$hours = (float) getenv('QA_HOURS');
if ($hours > 0) {
    require_once __DIR__.'/../../../vendor/autoload.php';
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds((int) ($hours * 3600)));
    if (getenv('QA_CLOCK_DEBUG')) {
        register_shutdown_function(fn () => file_put_contents(__DIR__.'/clock.log', ($_SERVER['REQUEST_URI'] ?? '').' '.\Illuminate\Support\Carbon::now()->toDateTimeString()."\n", FILE_APPEND));
    }
}
