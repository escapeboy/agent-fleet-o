<?php

namespace App\Domain\Agent\Support;

use App\Domain\Agent\Models\AgentPolicy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Policy-as-code: turns an AgentPolicy's current rules into a portable YAML
 * document and strictly parses such a document back into a rules array.
 *
 * The create/update actions accept any rules array, so every check on
 * imported rules lives here: unknown keys, wrong types and unknown enum
 * values are rejected. Ids, team, agent binding, enabled state and status are
 * never part of the document — they are not portable between teams.
 */
final class AgentPolicyYamlSerializer
{
    public const API_VERSION = 'fleetq.io/v1';

    public const KIND = 'AgentPolicy';

    public const MAX_BYTES = 65536;

    private const WINDOWS = 'hour,day,week,month';

    private const TOP_LEVEL_KEYS = ['apiVersion', 'kind', 'metadata', 'rules'];

    private const METADATA_KEYS = ['name'];

    public function serialize(AgentPolicy $policy): string
    {
        /** @var array<string, mixed> $rules */
        $rules = $policy->currentVersion->rules ?? [];

        return $this->serializeRules($policy->name, $rules);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function serializeRules(?string $name, array $rules): string
    {
        $doc = [
            'apiVersion' => self::API_VERSION,
            'kind' => self::KIND,
            'metadata' => ['name' => $name],
            'rules' => array_merge($this->defaultRules(), $rules),
        ];

        return Yaml::dump($doc, 6, 2, Yaml::DUMP_NULL_AS_TILDE | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /**
     * @return array{name: ?string, rules: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function parse(string $yaml): array
    {
        if (strlen($yaml) > self::MAX_BYTES) {
            $this->fail('The YAML document is too large (max 64 KB).');
        }

        try {
            $doc = Yaml::parse($yaml);
        } catch (ParseException $e) {
            $this->fail('Invalid YAML: '.$e->getMessage());
        }

        if (! is_array($doc) || ($doc !== [] && array_is_list($doc))) {
            $this->fail('The YAML document must be a mapping with apiVersion, kind, metadata and rules.');
        }

        $this->rejectUnknownKeys($doc, self::TOP_LEVEL_KEYS, '');

        if (($doc['apiVersion'] ?? null) !== self::API_VERSION) {
            $this->fail('apiVersion must be "'.self::API_VERSION.'".');
        }
        if (($doc['kind'] ?? null) !== self::KIND) {
            $this->fail('kind must be "'.self::KIND.'".');
        }

        $name = null;
        if (array_key_exists('metadata', $doc) && $doc['metadata'] !== null) {
            $metadata = $doc['metadata'];
            if (! is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
                $this->fail('metadata must be a mapping.');
            }
            $this->rejectUnknownKeys($metadata, self::METADATA_KEYS, 'metadata.');

            $rawName = $metadata['name'] ?? null;
            if ($rawName !== null && (! is_string($rawName) || mb_strlen($rawName) > 200)) {
                $this->fail('metadata.name must be a string of at most 200 characters.');
            }
            $name = is_string($rawName) && trim($rawName) !== '' ? trim($rawName) : null;
        }

        $rawRules = $doc['rules'] ?? null;
        if (! is_array($rawRules) || ($rawRules !== [] && array_is_list($rawRules))) {
            $this->fail('rules must be a mapping.');
        }

        $defaults = $this->defaultRules();
        $this->rejectUnknownKeys($rawRules, array_keys($defaults), 'rules.');

        $rules = array_merge($defaults, $rawRules);

        $validator = Validator::make($rules, [
            'allowed_target_types' => ['present', 'nullable', 'array', 'list'],
            'allowed_target_types.*' => ['string', 'max:200'],
            'denied_target_types' => ['present', 'array', 'list'],
            'denied_target_types.*' => ['string', 'max:200'],
            'risk_ceiling' => ['required', 'string', 'in:low,medium,high,critical'],
            'auto_execute' => ['required', 'array:enabled,threshold'],
            'auto_execute.enabled' => ['required', 'boolean:strict'],
            'auto_execute.threshold' => ['required', 'integer:strict', 'min:0', 'max:25'],
            'spend_cap' => ['present', 'nullable', 'array:credits,window'],
            'spend_cap.credits' => ['required_with:spend_cap', 'integer:strict', 'min:0'],
            'spend_cap.window' => ['required_with:spend_cap', 'string', 'in:'.self::WINDOWS],
            'frequency_cap' => ['present', 'nullable', 'array:count,window'],
            'frequency_cap.count' => ['required_with:frequency_cap', 'integer:strict', 'min:0'],
            'frequency_cap.window' => ['required_with:frequency_cap', 'string', 'in:'.self::WINDOWS],
            'sensitive_paths' => ['present', 'array', 'list'],
            'sensitive_paths.*' => ['string', 'max:500'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $key = (string) ($errors->keys()[0] ?? 'rules');
            $this->fail('Invalid value for rules.'.$key.': '.$errors->first($key));
        }

        return ['name' => $name, 'rules' => $rules];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultRules(): array
    {
        $defaults = config('agent_policies.default_rules', []);

        return is_array($defaults) ? $defaults : [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $data, array $allowed, string $prefix): void
    {
        $unknown = array_diff(array_map('strval', array_keys($data)), $allowed);
        if ($unknown !== []) {
            $this->fail('Unknown key '.$prefix.implode(', '.$prefix, $unknown).'. Allowed: '.implode(', ', $allowed).'.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['yamlImport' => $message]);
    }
}
