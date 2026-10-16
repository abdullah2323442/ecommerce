<?php

namespace Tests\Unit;

use Tests\TestCase;

class InertiaConfigTest extends TestCase
{
    public function test_inertia_testing_page_paths_points_to_lowercase_pages_directory(): void
    {
        $this->assertContains(resource_path('js/pages'), config('inertia.testing.page_paths'));
    }
}
