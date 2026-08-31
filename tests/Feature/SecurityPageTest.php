<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_security_page_is_publicly_reachable(): void
    {
        $this->get('/security')->assertOk();
    }
}
