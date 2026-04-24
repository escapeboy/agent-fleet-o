<?php

namespace Tests\Unit\Domain\Migration;

use App\Domain\Migration\Services\Importers\ContactImporter;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactImporterTest extends TestCase
{
    use RefreshDatabase;

    private ContactImporter $importer;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new ContactImporter;
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T',
            'slug' => 'team-t',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
    }

    public function test_creates_contact_from_mapped_row(): void
    {
        $errors = [];
        $outcome = $this->importer->importRow(
            $this->team->id,
            ['Name' => 'Jane Doe', 'Email' => 'jane@example.com', 'Phone' => '+359123'],
            ['Name' => 'display_name', 'Email' => 'email', 'Phone' => 'phone'],
            function (string $m) use (&$errors) { $errors[] = $m; },
        );

        $this->assertSame('created', $outcome);
        $this->assertEmpty($errors);
        $this->assertDatabaseHas('contact_identities', [
            'team_id' => $this->team->id,
            'email' => 'jane@example.com',
            'display_name' => 'Jane Doe',
            'phone' => '+359123',
        ]);
    }

    public function test_dedup_on_email_returns_updated_or_skipped(): void
    {
        ContactIdentity::create([
            'team_id' => $this->team->id,
            'display_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => null,
        ]);

        // Second import with an extra phone should update.
        $errors = [];
        $outcome = $this->importer->importRow(
            $this->team->id,
            ['Email' => 'jane@example.com', 'Phone' => '+359999'],
            ['Email' => 'email', 'Phone' => 'phone'],
            function (string $m) use (&$errors) { $errors[] = $m; },
        );
        $this->assertSame('updated', $outcome);

        // Third identical import should be skipped (no diff).
        $outcome2 = $this->importer->importRow(
            $this->team->id,
            ['Email' => 'jane@example.com', 'Phone' => '+359999'],
            ['Email' => 'email', 'Phone' => 'phone'],
            function () {},
        );
        $this->assertSame('skipped', $outcome2);
    }

    public function test_row_without_email_and_name_is_skipped(): void
    {
        $errors = [];
        $outcome = $this->importer->importRow(
            $this->team->id,
            ['Source' => 'legacy'],
            ['Source' => 'metadata'],
            function (string $m) use (&$errors) { $errors[] = $m; },
        );

        $this->assertSame('skipped', $outcome);
        $this->assertNotEmpty($errors);
    }

    public function test_unmapped_columns_land_in_metadata(): void
    {
        $this->importer->importRow(
            $this->team->id,
            ['Email' => 'x@example.com', 'LegacyTag' => 'vip'],
            ['Email' => 'email'],
            function () {},
        );

        $contact = ContactIdentity::where('email', 'x@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertSame(['LegacyTag' => 'vip'], $contact->metadata);
    }
}
