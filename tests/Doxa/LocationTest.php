<?php

namespace Tests\Doxa;

use function dirname;

class LocationTest extends TestCase
{
    public function test_the_package_install_path_is_treated_as_internal(): void
    {
        $this->assertSame(
            dirname(__DIR__, 2),
            $this->readPrivate($this->core->sensor->location, 'nightwatchPath'),
            'Upstream renamed or removed Location::$nightwatchPath: update DoxaWatchServiceProvider::markPackageFilesAsInternal().',
        );
    }
}
