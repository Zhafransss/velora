<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // public/build tidak ada di git (CI/clone baru), jadi @vite tidak boleh membaca manifest saat test.
        $this->withoutVite();
    }
}
