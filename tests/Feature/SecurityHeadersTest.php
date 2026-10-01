<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_sicherheits_header_auf_jeder_antwort(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");

        // Auch Fehlerseiten und Antworten außerhalb des Panels
        $this->get('/kalender/events.ics')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_in_produktion_nur_ueber_https(): void
    {
        $this->app['env'] = 'production';

        $this->get('http://localhost/login?x=1')
            ->assertStatus(301)
            ->assertRedirect('https://localhost/login?x=1');

        $this->get('https://localhost/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
