<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicEndpointRateLimitTest extends TestCase
{
    public function test_public_and_authenticated_write_routes_have_explicit_rate_limits(): void
    {
        $expected = [
            'community.like' => 'throttle:30,5',
            'community.post' => 'throttle:10,5',
            'learn.progress' => 'throttle:60,1',
            'courses.enroll' => 'throttle:10,5',
            'contact.submit' => 'throttle:10,5',
            'results.lookup' => 'throttle:30,5',
            'certificates.lookup' => 'throttle:30,5',
            'recover.request' => 'throttle:10,5',
            'recover.confirm' => 'throttle:10,5',
        ];

        foreach ($expected as $routeName => $middleware) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Expected route {$routeName} to be registered.");
            $this->assertContains($middleware, $route->gatherMiddleware(), "Expected {$routeName} to use {$middleware}.");
        }
    }
}
