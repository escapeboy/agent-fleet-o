<?php

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LandingFooterLicenseTest extends TestCase
{
    private const LICENSE_URL = 'https://github.com/escapeboy/agent-fleet-o/blob/main/LICENSE';

    public function test_footer_states_agpl_and_links_to_license(): void
    {
        $this->withoutVite();

        $response = $this->get('/terms');

        $response->assertOk();
        $response->assertSee('AGPL-3.0', false);
        $response->assertSee('href="'.self::LICENSE_URL.'"', false);
    }

    public function test_terms_page_names_agpl_for_community_edition(): void
    {
        $this->withoutVite();

        $response = $this->get('/terms');

        $response->assertOk();
        $response->assertSee('GNU Affero General Public License');
        $response->assertSee('AGPL-3.0');
        $response->assertDontSee('MIT License');
    }

    public function test_no_public_view_claims_mit_licence(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // welcome.blade.php only carries the third-party Tailwind "MIT License" banner.
            if ($file->getRelativePathname() === 'welcome.blade.php') {
                continue;
            }

            if (preg_match('/\bMIT\s+licen[sc]e(d)?\b/i', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Views still claim an MIT licence: '.implode(', ', $offenders));
    }

    public function test_readme_does_not_call_license_mit_friendly(): void
    {
        $readme = File::get(base_path('README.md'));

        $this->assertStringNotContainsString('MIT-friendly', $readme);
        $this->assertStringContainsString('AGPL', $readme);
    }
}
