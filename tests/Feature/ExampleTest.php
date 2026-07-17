<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_accessible(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_home_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
