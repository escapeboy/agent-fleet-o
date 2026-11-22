<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Support\AgentPolicyYamlSerializer;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AgentPolicyYamlSerializerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fullRules(): array
    {
        return [
            'allowed_target_types' => ['tool_call', 'git_push'],
            'denied_target_types' => ['migration', 'integration_action'],
            'risk_ceiling' => 'critical',
            'auto_execute' => ['enabled' => true, 'threshold' => 21],
            'spend_cap' => ['credits' => 5000, 'window' => 'day'],
            'frequency_cap' => ['count' => 50, 'window' => 'hour'],
            'sensitive_paths' => ['app/**/Auth/**', '**/Billing/**'],
        ];
    }

    private function assertRejected(string $yaml, string $needle): void
    {
        try {
            (new AgentPolicyYamlSerializer)->parse($yaml);
            $this->fail('Expected a ValidationException for: '.$needle);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('yamlImport', $errors);
            $this->assertStringContainsString($needle, implode(' ', $errors['yamlImport']));
        }
    }

    public function test_round_trip_of_default_rules(): void
    {
        $serializer = new AgentPolicyYamlSerializer;
        $defaults = config('agent_policies.default_rules');

        $parsed = $serializer->parse($serializer->serializeRules('Team default', $defaults));

        $this->assertSame('Team default', $parsed['name']);
        $this->assertSame($defaults, $parsed['rules']);
    }

    public function test_round_trip_of_full_rules(): void
    {
        $serializer = new AgentPolicyYamlSerializer;
        $rules = $this->fullRules();

        $yaml = $serializer->serializeRules('Strict', $rules);
        $parsed = $serializer->parse($yaml);

        $this->assertSame('Strict', $parsed['name']);
        $this->assertSame($rules, $parsed['rules']);

        $doc = Yaml::parse($yaml);
        $this->assertSame('fleetq.io/v1', $doc['apiVersion']);
        $this->assertSame('AgentPolicy', $doc['kind']);
    }

    public function test_partial_rules_are_merged_over_defaults_and_name_is_optional(): void
    {
        $parsed = (new AgentPolicyYamlSerializer)->parse(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  risk_ceiling: low\n",
        );

        $this->assertNull($parsed['name']);
        $this->assertSame('low', $parsed['rules']['risk_ceiling']);
        $this->assertSame(['migration'], $parsed['rules']['denied_target_types']);
    }

    public function test_rejects_unknown_rule_key(): void
    {
        $this->assertRejected(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  risk_ceiling: low\n  allow_everything: true\n",
            'allow_everything',
        );
    }

    public function test_rejects_unknown_top_level_key(): void
    {
        $this->assertRejected(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nteam_id: abc\nrules: {}\n",
            'team_id',
        );
    }

    public function test_rejects_bad_enum_and_type(): void
    {
        $this->assertRejected(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  risk_ceiling: yolo\n",
            'risk_ceiling',
        );
        $this->assertRejected(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  spend_cap:\n    credits: 10\n    window: year\n",
            'spend_cap.window',
        );
        $this->assertRejected(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  auto_execute:\n    enabled: true\n    threshold: 99\n",
            'auto_execute.threshold',
        );
        $this->assertRejected(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  denied_target_types: migration\n",
            'denied_target_types',
        );
    }

    public function test_rejects_quoted_numbers_and_booleans(): void
    {
        $this->assertRejected(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  auto_execute:\n    enabled: true\n    threshold: '20'\n",
            'auto_execute.threshold',
        );
        $this->assertRejected(
            "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  auto_execute:\n    enabled: 'yes'\n    threshold: 20\n",
            'auto_execute.enabled',
        );
    }

    public function test_rejects_wrong_kind_invalid_yaml_and_non_mapping(): void
    {
        $this->assertRejected("apiVersion: fleetq.io/v1\nkind: Workflow\nrules: {}\n", 'kind');
        $this->assertRejected("rules: [unclosed\n", 'YAML');
        $this->assertRejected('just a string', 'mapping');
    }

    public function test_rejects_oversized_input(): void
    {
        $this->assertRejected(str_repeat('a', 70 * 1024), 'too large');
    }
}
