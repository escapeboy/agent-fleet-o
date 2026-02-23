<?php

namespace App\Mcp\Tools\Budget;

use App\Domain\Budget\Services\SpendForecaster;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class BudgetForecastTool extends Tool
{
    protected string $name = 'budget_forecast';

    protected string $description = 'Forecast AI spend based on historical usage. Returns daily averages (7d and 30d), projected spend for the next 30 days, estimated days until the budget cap is reached, and a 30-day daily spend series.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $forecast = app(SpendForecaster::class)->forecast();

        // Drop the raw series from the response to keep it concise; include summary only
        $result = $forecast;
        unset($result['daily_series']);

        $result['daily_series_last_7d'] = collect($forecast['daily_series'])
            ->slice(-7)
            ->values()
            ->toArray();

        return Response::text(json_encode($result));
    }
}
