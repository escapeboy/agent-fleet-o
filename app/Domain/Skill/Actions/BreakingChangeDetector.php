<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\DTOs\BreakingChange;
use App\Domain\Skill\Models\SkillVersion;

class BreakingChangeDetector
{
    /**
     * Detect breaking changes between an older and newer SkillVersion input schema.
     *
     * @return array<int, BreakingChange>
     */
    public function execute(SkillVersion $old, SkillVersion $new): array
    {
        $oldSchema = is_array($old->input_schema) ? $old->input_schema : [];
        $newSchema = is_array($new->input_schema) ? $new->input_schema : [];

        $oldProps = $oldSchema['properties'] ?? [];
        $newProps = $newSchema['properties'] ?? [];
        $oldRequired = $oldSchema['required'] ?? [];
        $newRequired = $newSchema['required'] ?? [];

        $changes = [];

        // Rule 1: field_removed — property listed in old required[] is absent in new properties.
        foreach ($oldRequired as $field) {
            if (! array_key_exists($field, $newProps)) {
                $changes[] = new BreakingChange(
                    kind: 'field_removed',
                    field: $field,
                    message: "Required field '{$field}' was removed",
                    oldValue: 'present',
                    newValue: null,
                );
            }
        }

        // Rule 4: required_added — property existed in both, NOT in old required[], IS in new required[].
        foreach ($newRequired as $field) {
            if (in_array($field, $oldRequired, true)) {
                continue;
            }
            if (array_key_exists($field, $oldProps) && array_key_exists($field, $newProps)) {
                $changes[] = new BreakingChange(
                    kind: 'required_added',
                    field: $field,
                    message: "Field '{$field}' became required",
                    oldValue: 'optional',
                    newValue: 'required',
                );
            }
        }

        // Per-property rules: type narrowing + enum value removal.
        foreach ($oldProps as $name => $oldDef) {
            if (! array_key_exists($name, $newProps)) {
                continue;
            }
            $newDef = $newProps[$name];

            // Rule 2: type_narrowed
            $oldType = $oldDef['type'] ?? null;
            $newType = $newDef['type'] ?? null;

            if ($this->isTypeNarrowed($oldType, $newType)) {
                $changes[] = new BreakingChange(
                    kind: 'type_narrowed',
                    field: $name,
                    message: "Type of '{$name}' was narrowed from ".$this->formatType($oldType).' to '.$this->formatType($newType),
                    oldValue: $this->formatType($oldType),
                    newValue: $this->formatType($newType),
                );
            }

            // Rule 3: enum_value_removed
            $oldEnum = $oldDef['enum'] ?? null;
            $newEnum = $newDef['enum'] ?? null;
            if (is_array($oldEnum) && is_array($newEnum)) {
                foreach ($oldEnum as $value) {
                    if (! in_array($value, $newEnum, true)) {
                        $changes[] = new BreakingChange(
                            kind: 'enum_value_removed',
                            field: $name,
                            message: "Enum value '".$this->stringify($value)."' was removed from '{$name}'",
                            oldValue: $this->stringify($value),
                            newValue: null,
                        );
                    }
                }
            }
        }

        return $changes;
    }

    /**
     * A type is "narrowed" when:
     *  - old is a union (array) and new is a single (string)
     *  - old is a union (array) and new is also a union but with a subset (types removed)
     *  - old was missing/`any` and new is a specific type
     */
    private function isTypeNarrowed(mixed $oldType, mixed $newType): bool
    {
        // From union to single
        if (is_array($oldType) && is_string($newType)) {
            return true;
        }

        // From union to a smaller union
        if (is_array($oldType) && is_array($newType)) {
            foreach ($oldType as $t) {
                if (! in_array($t, $newType, true)) {
                    return true;
                }
            }

            return false;
        }

        // From any/missing to specific
        $oldIsAny = $oldType === null || $oldType === 'any';
        $newIsSpecific = (is_string($newType) && $newType !== 'any') || is_array($newType);

        return $oldIsAny && $newIsSpecific;
    }

    private function formatType(mixed $type): string
    {
        if ($type === null) {
            return 'any';
        }
        if (is_array($type)) {
            return '['.implode('|', $type).']';
        }

        return (string) $type;
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value) ?: '';
    }
}
