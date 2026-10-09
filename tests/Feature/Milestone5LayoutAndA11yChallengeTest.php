<?php

namespace Tests\Feature;

use Tests\TestCase;

class Milestone5LayoutAndA11yChallengeTest extends TestCase
{
    private string $jsBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsBasePath = resource_path('js');
    }

    /**
     * DASH-01 & DASH-02:
     * Verify that the 6 KPI skeleton cards in AttendanceDashboard.vue match the layout geometry of the real KPI metrics grid.
     */
    public function test_kpi_skeleton_grid_matches_live_kpi_geometry_in_attendance_dashboard(): void
    {
        $filePath = $this->jsBasePath . '/components/attendance/AttendanceDashboard.vue';
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        // 1. Loading Skeleton Container
        $this->assertMatchesRegularExpression(
            '/<div\s+v-if="attendanceStore\.loading"\s+class="([^"]*)"\s+aria-hidden="true">/',
            $content,
            'AttendanceDashboard.vue must have an aria-hidden skeleton container bound to attendanceStore.loading'
        );

        preg_match('/<div\s+v-if="attendanceStore\.loading"\s+class="([^"]*)"/', $content, $skelGridMatch);
        preg_match('/<div\s+v-else\s+class="([^"]*)"/', $content, $liveGridMatch);

        $this->assertNotEmpty($skelGridMatch, 'Skeleton grid container not found');
        $this->assertNotEmpty($liveGridMatch, 'Live grid container not found');

        $skelGridClasses = explode(' ', trim($skelGridMatch[1]));
        $liveGridClasses = explode(' ', trim($liveGridMatch[1]));
        sort($skelGridClasses);
        sort($liveGridClasses);

        // The grid classes must match identically to eliminate CLS (Cumulative Layout Shift)
        $this->assertEquals(
            $skelGridClasses,
            $liveGridClasses,
            'Skeleton grid classes must identically match live KPI grid classes (grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4)'
        );

        // 2. Card count verification
        $this->assertMatchesRegularExpression(
            '/v-for="i in 6"/',
            $content,
            'Skeleton loader must render exactly 6 cards'
        );

        // Count real KPI cards in live metrics grid
        // Split content at v-else and before next major section (Live Real-Time Ticker)
        $afterLive = explode('<!-- Live Real-Time Ticker', $content)[0];
        $liveSection = explode('<div v-else', $afterLive)[1];
        $liveCardCount = substr_count($liveSection, 'class="bg-white border border-slate-200/80 p-4 rounded-2xl shadow-xs"');
        $this->assertSame(6, $liveCardCount, 'Live KPI grid must contain exactly 6 metric cards');

        // 3. Card container styling and padding
        preg_match('/v-for="i in 6"[^>]*class="([^"]*)"/', $content, $skelCardMatch);
        $this->assertNotEmpty($skelCardMatch, 'Skeleton card template element not found');
        $skelCardClasses = $skelCardMatch[1];

        $requiredCardClasses = [
            'bg-white',
            'border',
            'border-slate-200/80',
            'p-4',
            'rounded-2xl',
            'shadow-xs',
            'animate-pulse',
            'motion-reduce:animate-none',
            'space-y-2',
        ];

        foreach ($requiredCardClasses as $class) {
            $this->assertStringContainsString(
                $class,
                $skelCardClasses,
                "Skeleton card is missing class: {$class}"
            );
        }

        // 4. Skeleton inner placeholders (3 vertical tiers corresponding to title, value, subtitle)
        $this->assertStringContainsString('class="h-3 bg-slate-200 rounded w-20"', $content, 'Missing title skeleton placeholder');
        $this->assertStringContainsString('class="h-8 bg-slate-200 rounded w-16"', $content, 'Missing metric value skeleton placeholder');
        $this->assertStringContainsString('class="h-2.5 bg-slate-100 rounded w-24"', $content, 'Missing subtitle skeleton placeholder');

        // 5. Reduced-motion compliance on live attendance indicator
        $this->assertMatchesRegularExpression(
            '/class="[^"]*rounded-full\s+bg-emerald-500\s+animate-pulse\s+motion-reduce:animate-none[^"]*"/',
            $content,
            'Live attendance stream indicator must include motion-reduce:animate-none'
        );

        // 6. onMounted automated roster fetch
        $this->assertStringContainsString('onMounted', $content);
        $this->assertStringContainsString('attendanceStore.fetchDailyAttendance()', $content);
    }

    /**
     * HUB-01:
     * Verify that all 4 sub-hubs (AttendanceHub.vue, ScheduleHub.vue, VisitorHub.vue, SettingsHub.vue)
     * have role="tablist" with flex-wrap and valid tab/tabpanel attributes.
     */
    public function test_attendance_hub_tablist_and_panel_semantics(): void
    {
        $filePath = $this->jsBasePath . '/components/attendance/AttendanceHub.vue';
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        // Tablist container
        $this->assertMatchesRegularExpression(
            '/<div\s+role="tablist"\s+aria-label="Attendance navigation tabs"\s+class="[^"]*flex[^"]*flex-wrap[^"]*"/',
            $content,
            'AttendanceHub tablist must have role="tablist", aria-label, and flex flex-wrap'
        );

        // Tabs
        $tabs = ['dashboard', 'roster'];
        foreach ($tabs as $tab) {
            // Check tab button
            $this->assertMatchesRegularExpression(
                '/<button[^>]*role="tab"[^>]*id="attendance-tab-' . $tab . '"[^>]*:aria-selected="activeSubTab === \'' . $tab . '\'\s*\?\s*\'true\'\s*:\s*\'false\'"[^>]*aria-controls="attendance-panel-' . $tab . '"/',
                $content,
                "AttendanceHub tab '{$tab}' is missing required WAI-ARIA tab attributes"
            );

            // Check tabpanel
            $this->assertMatchesRegularExpression(
                '/<div[^>]*id="attendance-panel-' . $tab . '"[^>]*role="tabpanel"[^>]*aria-labelledby="attendance-tab-' . $tab . '"[^>]*tabindex="0"/',
                $content,
                "AttendanceHub panel '{$tab}' is missing required WAI-ARIA tabpanel attributes"
            );
        }
    }

    public function test_schedule_hub_tablist_and_panel_semantics(): void
    {
        $filePath = $this->jsBasePath . '/components/schedules/ScheduleHub.vue';
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        // Tablist container
        $this->assertMatchesRegularExpression(
            '/<div\s+role="tablist"\s+aria-label="Schedule navigation tabs"\s+class="[^"]*flex[^"]*flex-wrap[^"]*"/',
            $content,
            'ScheduleHub tablist must have role="tablist", aria-label, and flex flex-wrap'
        );

        // Dynamic tabs definition
        $this->assertMatchesRegularExpression(
            '/<button[^>]*v-for="tab in tabs"[^>]*role="tab"[^>]*:id="\'schedule-tab-\'\s*\+\s*tab\.id"[^>]*:aria-selected="activeTab === tab\.id\s*\?\s*\'true\'\s*:\s*\'false\'"[^>]*:aria-controls="\'schedule-panel-\'\s*\+\s*tab\.id"/',
            $content,
            'ScheduleHub tabs loop is missing required WAI-ARIA tab attributes'
        );

        $tabs = ['shifts', 'assignments', 'holidays'];
        foreach ($tabs as $tab) {
            // Check tabpanel
            $this->assertMatchesRegularExpression(
                '/<div[^>]*id="schedule-panel-' . $tab . '"[^>]*role="tabpanel"[^>]*aria-labelledby="schedule-tab-' . $tab . '"[^>]*tabindex="0"/',
                $content,
                "ScheduleHub panel '{$tab}' is missing required WAI-ARIA tabpanel attributes"
            );
        }
    }

    public function test_visitor_hub_tablist_and_panel_semantics(): void
    {
        $filePath = $this->jsBasePath . '/components/visitors/VisitorHub.vue';
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        // Tablist container
        $this->assertMatchesRegularExpression(
            '/<div\s+role="tablist"\s+aria-label="Visitor management tabs"\s+class="[^"]*flex[^"]*flex-wrap[^"]*"/',
            $content,
            'VisitorHub tablist must have role="tablist", aria-label, and flex flex-wrap'
        );

        // Tabs
        $tabs = ['dashboard', 'watchlist'];
        foreach ($tabs as $tab) {
            // Check tab button
            $this->assertMatchesRegularExpression(
                '/<button[^>]*role="tab"[^>]*id="visitor-tab-' . $tab . '"[^>]*:aria-selected="activeSubTab === \'' . $tab . '\'\s*\?\s*\'true\'\s*:\s*\'false\'"[^>]*aria-controls="visitor-panel-' . $tab . '"/',
                $content,
                "VisitorHub tab '{$tab}' is missing required WAI-ARIA tab attributes"
            );

            // Check tabpanel
            $this->assertMatchesRegularExpression(
                '/<div[^>]*id="visitor-panel-' . $tab . '"[^>]*role="tabpanel"[^>]*aria-labelledby="visitor-tab-' . $tab . '"[^>]*tabindex="0"/',
                $content,
                "VisitorHub panel '{$tab}' is missing required WAI-ARIA tabpanel attributes"
            );
        }
    }

    public function test_settings_hub_tablist_and_panel_semantics(): void
    {
        $filePath = $this->jsBasePath . '/components/settings/SettingsHub.vue';
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        // Top header responsive wrapping
        $this->assertMatchesRegularExpression(
            '/<div\s+class="[^"]*flex\s+flex-col\s+sm:flex-row\s+sm:items-center\s+justify-between[^"]*"/',
            $content,
            'SettingsHub top bar must be responsive (flex-col sm:flex-row)'
        );

        // Tablist container
        $this->assertMatchesRegularExpression(
            '/<div\s+role="tablist"\s+aria-label="Settings navigation tabs"\s+class="[^"]*flex[^"]*flex-wrap[^"]*"/',
            $content,
            'SettingsHub tablist must have role="tablist", aria-label, and flex flex-wrap'
        );

        // Dynamic tabs definition
        $this->assertMatchesRegularExpression(
            '/<button[^>]*v-for="tab in tabs"[^>]*role="tab"[^>]*:id="\'settings-tab-\'\s*\+\s*tab\.id"[^>]*:aria-selected="activeTab === tab\.id\s*\?\s*\'true\'\s*:\s*\'false\'"[^>]*:aria-controls="\'settings-panel-\'\s*\+\s*tab\.id"/',
            $content,
            'SettingsHub tabs loop is missing required WAI-ARIA tab attributes'
        );

        $tabs = ['departments', 'access-groups', 'system', 'audit'];
        foreach ($tabs as $tab) {
            // Check tabpanel
            $this->assertMatchesRegularExpression(
                '/<div[^>]*id="settings-panel-' . $tab . '"[^>]*role="tabpanel"[^>]*aria-labelledby="settings-tab-' . $tab . '"[^>]*tabindex="0"/',
                $content,
                "SettingsHub panel '{$tab}' is missing required WAI-ARIA tabpanel attributes"
            );
        }
    }

    /**
     * LVE-06:
     * Verify LeaveCalendarView.vue has skeleton loader before empty check to eliminate empty-state flash.
     */
    public function test_leave_calendar_view_skeleton_and_flash_elimination(): void
    {
        $filePath = $this->jsBasePath . '/components/leave/LeaveCalendarView.vue';
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        // Skeleton container preceding empty check
        $this->assertMatchesRegularExpression(
            '/<div\s+v-if="leaveStore\.loading"\s+class="space-y-2\.5"\s+aria-hidden="true">/',
            $content,
            'LeaveCalendarView must have an aria-hidden skeleton container bound to leaveStore.loading'
        );

        // 3 rows rendered
        $this->assertMatchesRegularExpression(
            '/v-for="i in 3"/',
            $content,
            'LeaveCalendarView skeleton loader must render 3 rows'
        );

        // Reduced motion on skeleton pulse
        $this->assertStringContainsString(
            'animate-pulse motion-reduce:animate-none',
            $content,
            'LeaveCalendarView skeleton must support motion-reduce:animate-none'
        );

        // Empty state must use v-else-if, not v-if
        $this->assertMatchesRegularExpression(
            '/<div\s+v-else-if="approvedLeaves\.length === 0"/',
            $content,
            'LeaveCalendarView empty state must be v-else-if to prevent premature empty message flash while loading'
        );

        // onMounted automated fetch
        $this->assertStringContainsString('onMounted', $content);
        $this->assertStringContainsString('leaveStore.fetchLeaveRequests(1)', $content);
    }

    /**
     * DASH-02 & App.vue reduced-motion:
     * Verify App.vue implements motion-reduce:animate-none on all pulse and ping animations.
     */
    public function test_app_vue_reduced_motion_compliance(): void
    {
        $filePath = $this->jsBasePath . '/App.vue';
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        // 1. Alert count badge pulse
        $this->assertMatchesRegularExpression(
            '/class="[^"]*animate-pulse\s+motion-reduce:animate-none[^"]*"/',
            $content,
            'App.vue alert badge pulse must include motion-reduce:animate-none'
        );

        // 2. Metric skeleton pulse
        $this->assertMatchesRegularExpression(
            '/class="[^"]*animate-pulse\s+motion-reduce:animate-none\s+space-y-2[^"]*"/',
            $content,
            'App.vue metric skeleton must include motion-reduce:animate-none'
        );

        // 3. AI Alerts ping indicator
        $this->assertMatchesRegularExpression(
            '/class="[^"]*animate-ping\s+motion-reduce:animate-none[^"]*"/',
            $content,
            'App.vue unresolved alerts ping must include motion-reduce:animate-none'
        );
    }

    /**
     * Zero native window.confirm calls across all Vue components.
     */
    public function test_zero_native_window_confirm_across_frontend(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->jsBasePath)
        );

        $matches = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['vue', 'js', 'ts'])) {
                $content = file_get_contents($file->getPathname());
                if (str_contains($content, 'window.confirm(')) {
                    $matches[] = str_replace(base_path() . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertEmpty($matches, 'Found unexpected native window.confirm() in: ' . implode(', ', $matches));
    }
}
