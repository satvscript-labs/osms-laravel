<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-04 — Content-Security-Policy + self-hosted fonts.
 *
 * The policy ships Report-Only by default so a mistake can't take production down;
 * these tests pin both that default and the directives that actually matter.
 */
class Phase55SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $tenant = Tenant::create(['store_name' => 'CSP Optical', 'address' => 'X']);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    public function test_baseline_security_headers_are_present(): void
    {
        $response = $this->actingAs($this->user())->get(route('tenant.dashboard'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_csp_is_report_only_by_default_so_a_bad_policy_cannot_break_production(): void
    {
        $response = $this->actingAs($this->user())->get(route('tenant.dashboard'));

        $this->assertTrue($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse(
            $response->headers->has('Content-Security-Policy'),
            'CSP must not enforce until CSP_ENFORCE=true is set deliberately.'
        );
    }

    public function test_setting_csp_enforce_switches_to_the_blocking_header(): void
    {
        config(['security.csp_enforce' => true]);

        $response = $this->actingAs($this->user())->get(route('tenant.dashboard'));

        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_the_policy_locks_down_the_directives_that_matter(): void
    {
        $response = $this->actingAs($this->user())->get(route('tenant.dashboard'));
        $csp = $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);   // clickjacking
        $this->assertStringContainsString("form-action 'self'", $csp);       // form hijacking
        $this->assertStringContainsString("object-src 'none'", $csp);        // plugin injection
        $this->assertStringContainsString("base-uri 'self'", $csp);          // base-tag injection
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function test_the_policy_still_allows_razorpay_so_billing_keeps_working(): void
    {
        $response = $this->actingAs($this->user())->get(route('tenant.dashboard'));
        $csp = $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('https://checkout.razorpay.com', $csp);
        $this->assertStringContainsString('frame-src', $csp);
    }

    public function test_each_response_carries_a_fresh_nonce_in_the_policy(): void
    {
        $user = $this->user();

        $one = $this->actingAs($user)->get(route('tenant.dashboard'))
            ->headers->get('Content-Security-Policy-Report-Only');
        $two = $this->actingAs($user)->get(route('tenant.dashboard'))
            ->headers->get('Content-Security-Policy-Report-Only');

        preg_match("/'nonce-([^']+)'/", $one, $a);
        preg_match("/'nonce-([^']+)'/", $two, $b);

        $this->assertNotEmpty($a[1] ?? '', 'policy must carry a nonce');
        $this->assertNotSame($a[1], $b[1], 'a nonce must never be reused across requests');
    }

    public function test_inline_scripts_carry_the_nonce_so_they_survive_enforcement(): void
    {
        config(['security.csp_enforce' => true]);

        $response = $this->actingAs($this->user())->get(route('tenant.dashboard'));
        $html = $response->getContent();
        $csp = $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $nonce = $m[1] ?? '';

        $this->assertNotEmpty($nonce);
        // No inline <script> may be left without the nonce, or it silently dies.
        $this->assertStringNotContainsString('<script>', $html);
        if (str_contains($html, '<script')) {
            $this->assertStringContainsString('nonce="' . $nonce . '"', $html);
        }
    }

    public function test_no_page_pulls_fonts_from_a_third_party_cdn(): void
    {
        $response = $this->actingAs($this->user())->get(route('tenant.dashboard'));
        $html = $response->getContent();

        // SEC-04 — fonts are bundled; leaking visitor IPs to Google is a DPDP concern.
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
    }
}
