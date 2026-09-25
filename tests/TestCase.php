<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The panel's theme is a Vite asset (AdminPanelProvider::viteTheme()),
     * and the suite never builds front-end assets, so without this every
     * full-page render would fail looking for the Vite manifest. Tests
     * assert on markup, not CSS; CI's build job checks the theme compiles.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
