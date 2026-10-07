<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The framework's own smoke test, kept as one: it proves the application
     * boots and routes a request.
     *
     * It asserted a 200 from "/" because the scaffold served a page there. The
     * root path is now a signpost - there is nothing public in MealBells - so
     * the smoke test follows the redirect to the sign-in page. LandingRedirectTest
     * covers where each role is sent.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        $this->get('/login')->assertStatus(200);
    }
}
