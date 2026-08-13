<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The homepage renders the marketing landing page when its (privately
     * maintained, gitignored) view is deployed, and otherwise redirects to
     * the admin panel — both are healthy states for a fresh install.
     */
    public function test_the_application_root_responds_without_error(): void
    {
        $response = $this->get('/');

        if (view()->exists('welcome')) {
            $response->assertStatus(200);
        } else {
            $response->assertRedirect('/admin');
        }
    }
}
