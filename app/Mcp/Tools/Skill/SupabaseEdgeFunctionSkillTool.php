<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Enums\SkillType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SupabaseEdgeFunctionSkillTool extends Tool
{
    protected string $name = 'supabase_edge_function_skill';

    protected string $description = 'Get setup instructions and configuration schema for Supabase Edge Function skills. These skills invoke a Supabase Edge Function via REST — no LLM tokens consumed, costs billed to the user\'s Supabase account.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::text(json_encode([
            'skill_type' => SkillType::SupabaseEdgeFunction->value,
            'label' => SkillType::SupabaseEdgeFunction->label(),
            'description' => 'Invoke a Supabase Edge Function as a reusable FleetQ skill. The function receives the skill input as a JSON body and its JSON response becomes the skill output. No platform credits are consumed — compute is billed to your Supabase account.',
            'cost' => '0 FleetQ credits — billed to your Supabase account',
            'configuration_schema' => [
                'project_url' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Your Supabase project URL, e.g. https://xyzabcdef.supabase.co',
                    'example' => 'https://xyzabcdef.supabase.co',
                ],
                'function_name' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Edge function slug (URL path segment), e.g. process-data',
                    'example' => 'process-data',
                ],
                'credential_id' => [
                    'type' => 'string (UUID)',
                    'required' => true,
                    'description' => 'ID of a FleetQ Credential (type=api_key) containing your Supabase service_role_key in secret_data.key. Create via Credentials → New Credential.',
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 60,
                    'description' => 'Maximum seconds to wait for the function response. Supabase Edge Functions have a 60s wall-clock limit on free plans.',
                ],
                'method' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'POST',
                    'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                    'description' => 'HTTP method. Defaults to POST. Use GET for read-only functions that don\'t need a body.',
                ],
            ],
            'setup_steps' => [
                '1. Create a Supabase Edge Function in your project (supabase functions new my-function)',
                '2. Deploy it (supabase functions deploy my-function)',
                '3. In FleetQ → Credentials → New Credential, create an api_key credential with your service_role_key',
                '4. In FleetQ → Skills → New Skill, choose type "Supabase Edge Function"',
                '5. Set project_url, function_name, and credential_id in the skill configuration',
                '6. Define input_schema and output_schema matching your function\'s expected JSON',
                '7. Attach the skill to an agent or use it directly in experiments',
            ],
            'example_configuration' => [
                'project_url' => 'https://xyzabcdef.supabase.co',
                'function_name' => 'generate-report',
                'credential_id' => 'uuid-of-credential-with-service-role-key',
                'timeout_seconds' => 30,
                'method' => 'POST',
            ],
            'example_edge_function' => <<<'DENO'
// supabase/functions/generate-report/index.ts
import { serve } from "https://deno.land/std@0.168.0/http/server.ts"

serve(async (req) => {
  const { topic, format } = await req.json()

  // Your logic here...
  const report = `Report on ${topic} in ${format} format`

  return new Response(
    JSON.stringify({ report, generated_at: new Date().toISOString() }),
    { headers: { "Content-Type": "application/json" } },
  )
})
DENO,
            'notes' => [
                'The entire skill input array is sent as the function JSON body',
                'The function JSON response becomes the skill output',
                'Non-JSON responses are wrapped as {"raw": "<response body>"}',
                'HTTP 4xx/5xx responses are recorded as failed executions',
                'Edge Functions on free Supabase plans have a 60s execution limit',
                'Use ANON key for public functions, SERVICE_ROLE key for private ones',
            ],
        ]));
    }
}
