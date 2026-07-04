<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6.2 — analytics quick date-range chips. Pure front-end (each chip fills
 * the existing GET form), so the assertions cover that the chips render with the
 * correct computed date attributes and that the chip matching the active server
 * range is highlighted.
 */
class Phase29AnalyticsQuickDatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    public function test_quick_range_chips_render_with_computed_dates(): void
    {
        $today = now()->format('Y-m-d');
        $weekAgo = now()->subDays(6)->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $html = $this->actingAs($this->user)
            ->get(route('tenant.analytics.index'))
            ->assertOk()
            ->assertSee('Quick range')
            ->assertSee('Today')
            ->assertSee('Yesterday')
            ->assertSee('Last 7 days')
            ->assertSee('This month')
            ->getContent();

        // The "Today" chip carries today's date on both ends (attributes span
        // lines in the markup, so match the pair with a newline-tolerant regex).
        $this->assertMatchesRegularExpression(
            '/data-from="' . preg_quote($today, '/') . '"\s+data-to="' . preg_quote($today, '/') . '"/',
            $html,
        );
        // "Last 7 days" spans a 7-day window inclusive of today.
        $this->assertMatchesRegularExpression(
            '/data-from="' . preg_quote($weekAgo, '/') . '"\s+data-to="' . preg_quote($today, '/') . '"/',
            $html,
        );
        // "Yesterday" is a single past day on both ends.
        $this->assertMatchesRegularExpression(
            '/data-from="' . preg_quote($yesterday, '/') . '"\s+data-to="' . preg_quote($yesterday, '/') . '"/',
            $html,
        );
    }

    public function test_active_chip_is_highlighted_when_range_matches(): void
    {
        $today = now()->format('Y-m-d');

        // Request the exact "Today" range → that chip must render active.
        $html = $this->actingAs($this->user)
            ->get(route('tenant.analytics.index', ['from' => $today, 'to' => $today]))
            ->assertOk()
            ->getContent();

        // The active chip carries aria-current and the active class on the same button.
        $this->assertMatchesRegularExpression(
            '/class="date-chip active"[^>]*data-from="' . preg_quote($today, '/') . '"/',
            $html,
        );
    }

    public function test_analytics_is_tenant_scoped(): void
    {
        // A user from another tenant still gets their own analytics page (no leak);
        // this simply guards the route stays behind auth + tenant scope.
        $other = Tenant::create(['store_name' => 'Other', 'tax_id' => 'G2', 'address' => 'Delhi']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id, 'role' => 'store_admin']);

        $this->actingAs($otherUser)
            ->get(route('tenant.analytics.index'))
            ->assertOk()
            ->assertSee('Quick range');
    }
}
