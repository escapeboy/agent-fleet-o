# Contributing to FleetQ

## Development Setup

Requirements: PHP 8.4+, PostgreSQL 17+, Redis 7+, Node.js 20+, Composer

```bash
git clone https://github.com/escapeboy/agent-fleet-o.git
cd agent-fleet
make install        # Docker setup + wizard
# or
composer install && npm install && cp .env.example .env
php artisan app:install
```

## Branching

- **Default branch:** `develop` — open all PRs here
- **Naming:** `feat/<description>`, `fix/<description>`, `chore/<description>`
- **Hotfixes:** branch from `main`, PR into `main`, then sync back to `develop`

## Code Conventions

See `CLAUDE.md` for full conventions. Key points:

- **Models:** `HasUuids` (UUIDv7) + `BelongsToTeam` + `TeamScope` global scope
- **Actions:** Single `execute()` method, no repositories, use Eloquent directly
- **Enums:** PHP 8.1 backed enums in `Enums/` per domain
- **DTOs:** `readonly` properties
- **Database:** UUID primary keys, JSONB+GIN, partial indexes, reversible migrations

## Adding a New Feature

1. Add domain logic in `app/Domain/<Domain>/`
2. Add migration (UUID PK, `BelongsToTeam` columns)
3. Register `TeamScope` on the model
4. Add API controller in `app/Http/Controllers/Api/V1/`
5. **Add MCP tool(s) in `app/Mcp/Tools/<Domain>/`** (mandatory)
6. Register tools in `app/Mcp/Servers/AgentFleetServer.php`
7. Add Livewire component if UI is needed
8. Write feature tests

## MCP Tool Checklist

Every new domain capability **must** have a corresponding MCP tool. Tools extend `Laravel\Mcp\Server\Tool`:

```php
#[IsReadOnly]   // for read-only queries
#[IsIdempotent] // for safe repeated calls
class MyTool extends Tool
{
    protected string $name = 'domain_action';
    protected string $description = 'One sentence description.';

    public function schema(JsonSchema $schema): array
    {
        return $schema->object()->properties(
            $schema->string('param')->description('...')->required(),
        )->toArray();
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        // ...
        return Response::text(json_encode(['data' => $result]));
    }
}
```

- Omit `#[IsReadOnly]` / `#[IsIdempotent]` for write/destructive tools
- Use `Response::error($message)` for validation/not-found errors
- Register in `AgentFleetServer::$tools` array and update comment counts

## Running Tests

```bash
php artisan test                    # all tests
php artisan test --filter MyTest    # single test class
```

Tests use SQLite in-memory (no Docker needed for unit/feature tests). PostgreSQL-specific tests (RLS) are skipped automatically on SQLite.

## Pull Request

1. Tests pass (`php artisan test`)
2. All MCP tools added for new functionality
3. Migrations are reversible (have `down()`)
4. PR opened against `develop`
