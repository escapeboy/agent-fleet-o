<?php

declare(strict_types=1);

namespace App\Infrastructure\A2ui;

use Illuminate\Support\HtmlString;

/**
 * Renders A2UI protocol components (adjacency list) to Tailwind-styled HTML.
 *
 * Implements Google's A2UI v0.8 standard catalog:
 * - Flat adjacency list → tree reconstruction
 * - JSON Pointer data binding resolution
 * - 24 standard component types → Tailwind HTML
 *
 * @see https://a2ui.org/specification/v0.8-a2ui/
 */
class A2uiRenderer
{
    /**
     * Render an A2UI component list to HTML.
     *
     * @param  array<int, array{id: string, component: array}>  $components  Flat adjacency list
     * @param  array<string, mixed>  $dataModel  Data model for JSON Pointer binding
     */
    private const MAX_COMPONENTS = 500;

    private const MAX_DEPTH = 20;

    /**
     * Render an A2UI component list to HTML.
     *
     * @param  array<int, array{id: string, component: array}>  $components  Flat adjacency list
     * @param  array<string, mixed>  $dataModel  Data model for JSON Pointer binding
     */
    public function render(array $components, array $dataModel = []): HtmlString
    {
        if (empty($components)) {
            return new HtmlString('');
        }

        if (count($components) > self::MAX_COMPONENTS) {
            return new HtmlString('<!-- A2UI: component limit exceeded ('.count($components).' > '.self::MAX_COMPONENTS.') -->');
        }

        $map = $this->buildMap($components);
        $rootId = $this->findRootId($map);

        $visited = [];
        $depth = 0;
        $html = $this->renderNode($rootId, $map, $dataModel, $visited, $depth);

        return new HtmlString('<div class="a2ui-surface space-y-3">'.$html.'</div>');
    }

    /**
     * Validate an A2UI component list structure.
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(array $components): array
    {
        $errors = [];

        if (empty($components)) {
            return ['valid' => true, 'errors' => []];
        }

        $ids = [];
        foreach ($components as $i => $component) {
            if (! isset($component['id'])) {
                $errors[] = "Component at index {$i} missing 'id'";

                continue;
            }
            if (! isset($component['component']) || ! is_array($component['component'])) {
                $errors[] = "Component '{$component['id']}' missing 'component' definition";

                continue;
            }
            if (in_array($component['id'], $ids)) {
                $errors[] = "Duplicate component id '{$component['id']}'";
            }
            $ids[] = $component['id'];

            $type = array_key_first($component['component']);
            if (! in_array($type, self::SUPPORTED_TYPES)) {
                $errors[] = "Component '{$component['id']}' uses unsupported type '{$type}'";
            }
        }

        // Validate child references
        $map = $this->buildMap($components);
        foreach ($map as $id => $node) {
            foreach ($this->extractChildIds($node) as $childId) {
                if (! isset($map[$childId])) {
                    $errors[] = "Component '{$id}' references missing child '{$childId}'";
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private const SUPPORTED_TYPES = [
        'Text', 'Button', 'Card', 'Row', 'Column', 'List', 'Image', 'Icon',
        'Badge', 'Progress', 'Alert', 'Stat', 'Divider', 'Avatar', 'Rating',
        'Tabs', 'Modal', 'TextField', 'CheckBox', 'ChoicePicker', 'Slider',
        'DateTimeInput', 'Video', 'AudioPlayer',
    ];

    /** @return array<string, array> */
    private function buildMap(array $components): array
    {
        $map = [];
        foreach ($components as $component) {
            if (isset($component['id'])) {
                $map[$component['id']] = $component;
            }
        }

        return $map;
    }

    private function findRootId(array $map): string
    {
        $childIds = [];
        foreach ($map as $node) {
            $childIds = array_merge($childIds, $this->extractChildIds($node));
        }

        foreach ($map as $id => $node) {
            if (! in_array($id, $childIds)) {
                return $id;
            }
        }

        return array_key_first($map);
    }

    /** @return list<string> */
    private function extractChildIds(array $node): array
    {
        $comp = $node['component'] ?? [];
        $type = array_key_first($comp);
        $props = $comp[$type] ?? [];
        $ids = [];

        if (isset($props['child']) && is_string($props['child'])) {
            $ids[] = $props['child'];
        }
        if (isset($props['children']['explicitList']) && is_array($props['children']['explicitList'])) {
            $ids = array_merge($ids, $props['children']['explicitList']);
        }
        // Tabs: each tab has a content child
        if ($type === 'Tabs' && isset($props['tabs']) && is_array($props['tabs'])) {
            foreach ($props['tabs'] as $tab) {
                if (isset($tab['content']) && is_string($tab['content'])) {
                    $ids[] = $tab['content'];
                }
            }
        }

        return $ids;
    }

    private function renderNode(string $id, array $map, array $dataModel, array &$visited = [], int $depth = 0): string
    {
        if (isset($visited[$id])) {
            return '<!-- A2UI: cycle detected at "'.$this->e($id).'" -->';
        }
        if ($depth > self::MAX_DEPTH) {
            return '<!-- A2UI: max nesting depth exceeded -->';
        }
        $visited[$id] = true;

        $node = $map[$id] ?? null;
        if (! $node) {
            return '';
        }

        $comp = $node['component'] ?? [];
        $type = array_key_first($comp);
        $props = $comp[$type] ?? [];

        $props = $this->resolveBindings($props, $dataModel);

        return match ($type) {
            'Text' => $this->renderText($props),
            'Button' => $this->renderButton($props),
            'Card' => $this->renderCard($props, $map, $dataModel, $visited, $depth),
            'Row' => $this->renderRow($props, $map, $dataModel, $visited, $depth),
            'Column' => $this->renderColumn($props, $map, $dataModel, $visited, $depth),
            'List' => $this->renderList($props, $map, $dataModel, $visited, $depth),
            'Image' => $this->renderImage($props),
            'Icon' => $this->renderIcon($props),
            'Badge' => $this->renderBadge($props),
            'Progress' => $this->renderProgress($props),
            'Alert' => $this->renderAlert($props),
            'Stat' => $this->renderStat($props),
            'Divider' => '<hr class="my-3 border-gray-200 dark:border-gray-700">',
            'Avatar' => $this->renderAvatar($props),
            'Rating' => $this->renderRating($props),
            'Tabs' => $this->renderTabs($props, $map, $dataModel, $visited, $depth),
            'Modal' => $this->renderModal($props, $map, $dataModel, $visited, $depth),
            'TextField' => $this->renderTextField($props),
            'CheckBox' => $this->renderCheckBox($props),
            'ChoicePicker' => $this->renderChoicePicker($props),
            'Slider' => $this->renderSlider($props),
            'DateTimeInput' => $this->renderDateTimeInput($props),
            'Video' => $this->renderVideo($props),
            'AudioPlayer' => $this->renderAudioPlayer($props),
            default => '<!-- A2UI: unsupported component "'.$this->e($type).'" -->',
        };
    }

    private function renderChildren(array $props, array $map, array $dataModel, array &$visited, int $depth): string
    {
        $childIds = [];
        if (isset($props['child']) && is_string($props['child'])) {
            $childIds[] = $props['child'];
        }
        if (isset($props['children']['explicitList']) && is_array($props['children']['explicitList'])) {
            $childIds = array_merge($childIds, $props['children']['explicitList']);
        }

        return implode('', array_map(
            fn (string $id) => $this->renderNode($id, $map, $dataModel, $visited, $depth + 1),
            $childIds,
        ));
    }

    // ─── Data Binding ────────────────────────────────────────────

    private function resolveBindings(array $props, array $dataModel): array
    {
        if (empty($dataModel)) {
            return $props;
        }

        foreach ($props as $key => $value) {
            if (is_array($value) && isset($value['path']) && is_string($value['path']) && count($value) === 1) {
                // JSON Pointer binding: {"path": "/user/name"}
                $resolved = $this->resolveJsonPointer($value['path'], $dataModel);
                if ($resolved !== null) {
                    $props[$key] = $resolved;
                }
            } elseif (is_array($value)) {
                // Recurse into nested arrays (tabs, options, etc.)
                $props[$key] = $this->resolveBindings($value, $dataModel);
            }
        }

        return $props;
    }

    private function resolveJsonPointer(string $pointer, array $data): mixed
    {
        $parts = explode('/', ltrim($pointer, '/'));
        $current = $data;

        foreach ($parts as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $part); // RFC 6901
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }

        return $current;
    }

    // ─── Component Renderers ─────────────────────────────────────

    private function renderText(array $props): string
    {
        $text = $this->e($props['text'] ?? '');
        $variant = $props['variant'] ?? 'body';

        return match ($variant) {
            'h1' => '<h1 class="text-2xl font-bold text-gray-900 dark:text-white">'.$text.'</h1>',
            'h2' => '<h2 class="text-xl font-semibold text-gray-900 dark:text-white">'.$text.'</h2>',
            'h3' => '<h3 class="text-lg font-semibold text-gray-900 dark:text-white">'.$text.'</h3>',
            'h4' => '<h4 class="text-base font-semibold text-gray-900 dark:text-white">'.$text.'</h4>',
            'caption' => '<span class="text-xs text-gray-500 dark:text-gray-400">'.$text.'</span>',
            'label' => '<span class="text-sm font-medium text-gray-700 dark:text-gray-300">'.$text.'</span>',
            'code' => '<code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-sm font-mono text-gray-800 dark:text-gray-200">'.$text.'</code>',
            default => '<p class="text-sm text-gray-700 dark:text-gray-300">'.$text.'</p>',
        };
    }

    private function renderButton(array $props): string
    {
        $label = $this->e($props['label'] ?? $props['text'] ?? 'Button');
        $variant = $props['variant'] ?? 'primary';
        $disabled = ! empty($props['disabled']) ? ' disabled' : '';
        $action = isset($props['action']) ? ' data-a2ui-action="'.$this->e((string) $props['action']).'"' : '';

        $classes = match ($variant) {
            'secondary' => 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700',
            'danger', 'destructive' => 'bg-red-600 text-white hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-600',
            'ghost' => 'bg-transparent text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800',
            'link' => 'bg-transparent text-primary-600 hover:underline dark:text-primary-400 px-0',
            default => 'bg-primary-600 text-white hover:bg-primary-700 dark:bg-primary-500 dark:hover:bg-primary-600',
        };

        return '<button class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed '.$classes.'"'.$disabled.$action.'>'.$label.'</button>';
    }

    private function renderCard(array $props, array $map, array $dataModel, array &$visited, int $depth): string
    {
        $childHtml = $this->renderChildren($props, $map, $dataModel, $visited, $depth);
        $padding = isset($props['padding']) ? 'padding: '.(int) $props['padding'].'px;' : '';
        $style = $padding ? ' style="'.$padding.'"' : '';

        return '<div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800"'.$style.'>'.$childHtml.'</div>';
    }

    private function renderRow(array $props, array $map, array $dataModel, array &$visited, int $depth): string
    {
        $childHtml = $this->renderChildren($props, $map, $dataModel, $visited, $depth);
        $gap = isset($props['gap']) ? 'gap-'.(int) $props['gap'] : 'gap-3';
        $align = match ($props['align'] ?? 'start') {
            'center' => 'items-center',
            'end' => 'items-end',
            'stretch' => 'items-stretch',
            default => 'items-start',
        };
        $justify = match ($props['justify'] ?? 'start') {
            'center' => 'justify-center',
            'end' => 'justify-end',
            'between' => 'justify-between',
            'around' => 'justify-around',
            default => 'justify-start',
        };

        return '<div class="flex flex-row flex-wrap '.$gap.' '.$align.' '.$justify.'">'.$childHtml.'</div>';
    }

    private function renderColumn(array $props, array $map, array $dataModel, array &$visited, int $depth): string
    {
        $childHtml = $this->renderChildren($props, $map, $dataModel, $visited, $depth);
        $gap = isset($props['gap']) ? 'gap-'.(int) $props['gap'] : 'gap-2';

        return '<div class="flex flex-col '.$gap.'">'.$childHtml.'</div>';
    }

    private function renderList(array $props, array $map, array $dataModel, array &$visited, int $depth): string
    {
        $childHtml = $this->renderChildren($props, $map, $dataModel, $visited, $depth);
        $divider = ! empty($props['divider']);

        if ($divider) {
            return '<div class="divide-y divide-gray-200 dark:divide-gray-700">'.$childHtml.'</div>';
        }

        return '<div class="space-y-2">'.$childHtml.'</div>';
    }

    private function renderImage(array $props): string
    {
        $src = $this->sanitizeUrl($props['src'] ?? $props['url'] ?? '');
        if (! $src) {
            return '<!-- A2UI: Image missing src -->';
        }

        $alt = $this->e($props['alt'] ?? '');
        $width = isset($props['width']) ? ' width="'.(int) $props['width'].'"' : '';
        $height = isset($props['height']) ? ' height="'.(int) $props['height'].'"' : '';
        $fit = match ($props['fit'] ?? 'cover') {
            'contain' => 'object-contain',
            'fill' => 'object-fill',
            'none' => 'object-none',
            default => 'object-cover',
        };
        $rounded = match ($props['borderRadius'] ?? null) {
            'full' => 'rounded-full',
            'lg' => 'rounded-lg',
            'none' => '',
            default => 'rounded-md',
        };

        return '<img src="'.$src.'" alt="'.$alt.'" class="max-w-full '.$fit.' '.$rounded.'"'.$width.$height.' loading="lazy">';
    }

    private function renderIcon(array $props): string
    {
        $name = $this->e($props['name'] ?? $props['icon'] ?? 'circle');
        $size = match ($props['size'] ?? 'md') {
            'xs' => 'w-3 h-3',
            'sm' => 'w-4 h-4',
            'lg' => 'w-6 h-6',
            'xl' => 'w-8 h-8',
            default => 'w-5 h-5',
        };
        $color = isset($props['color']) ? 'color: '.$this->sanitizeColor($props['color']).';' : '';
        $style = $color ? ' style="'.$color.'"' : '';

        // Render as a simple icon placeholder — agents specify icon names
        return '<span class="inline-flex items-center justify-center '.$size.' text-gray-500 dark:text-gray-400"'.$style.' title="'.$name.'" role="img" aria-label="'.$name.'">&#x25CF;</span>';
    }

    private function renderBadge(array $props): string
    {
        $text = $this->e($props['text'] ?? $props['label'] ?? '');
        $variant = $props['variant'] ?? $props['color'] ?? 'default';

        $colors = match ($variant) {
            'success', 'green' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'warning', 'yellow', 'amber' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
            'error', 'danger', 'red' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            'info', 'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            'purple' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
            default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
        };

        return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium '.$colors.'">'.$text.'</span>';
    }

    private function renderProgress(array $props): string
    {
        $value = max(0, min(100, (int) ($props['value'] ?? 0)));
        $label = $this->e($props['label'] ?? '');
        $showValue = ($props['showValue'] ?? true) !== false;
        $color = match ($props['color'] ?? 'primary') {
            'success', 'green' => 'bg-green-500',
            'warning', 'yellow' => 'bg-yellow-500',
            'error', 'danger', 'red' => 'bg-red-500',
            default => 'bg-primary-500',
        };

        $html = '';
        if ($label || $showValue) {
            $html .= '<div class="flex justify-between text-sm mb-1">';
            if ($label) {
                $html .= '<span class="text-gray-600 dark:text-gray-400">'.$label.'</span>';
            }
            if ($showValue) {
                $html .= '<span class="text-gray-500 dark:text-gray-400">'.$value.'%</span>';
            }
            $html .= '</div>';
        }
        $html .= '<div class="w-full h-2 bg-gray-200 rounded-full dark:bg-gray-700">';
        $html .= '<div class="h-2 rounded-full '.$color.' transition-all" style="width: '.$value.'%"></div>';
        $html .= '</div>';

        return '<div>'.$html.'</div>';
    }

    private function renderAlert(array $props): string
    {
        $message = $this->e($props['message'] ?? $props['text'] ?? '');
        $title = isset($props['title']) ? $this->e($props['title']) : null;
        $variant = $props['variant'] ?? $props['severity'] ?? 'info';

        [$bgColor, $borderColor, $textColor, $iconSvg] = match ($variant) {
            'success' => [
                'bg-green-50 dark:bg-green-900/20', 'border-green-200 dark:border-green-800',
                'text-green-800 dark:text-green-300',
                '<svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
            ],
            'warning' => [
                'bg-yellow-50 dark:bg-yellow-900/20', 'border-yellow-200 dark:border-yellow-800',
                'text-yellow-800 dark:text-yellow-300',
                '<svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>',
            ],
            'error', 'danger' => [
                'bg-red-50 dark:bg-red-900/20', 'border-red-200 dark:border-red-800',
                'text-red-800 dark:text-red-300',
                '<svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>',
            ],
            default => [
                'bg-blue-50 dark:bg-blue-900/20', 'border-blue-200 dark:border-blue-800',
                'text-blue-800 dark:text-blue-300',
                '<svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>',
            ],
        };

        $html = '<div class="flex gap-3 rounded-lg border p-4 '.$bgColor.' '.$borderColor.'">';
        $html .= '<div class="shrink-0">'.$iconSvg.'</div>';
        $html .= '<div class="'.$textColor.'">';
        if ($title) {
            $html .= '<p class="text-sm font-semibold">'.$title.'</p>';
        }
        $html .= '<p class="text-sm'.($title ? ' mt-1' : '').'">'.$message.'</p>';
        $html .= '</div></div>';

        return $html;
    }

    private function renderStat(array $props): string
    {
        $label = $this->e($props['label'] ?? '');
        $value = $this->e((string) ($props['value'] ?? '0'));
        $change = isset($props['change']) ? $this->e((string) $props['change']) : null;
        $trend = $props['trend'] ?? null;

        $trendClass = match ($trend) {
            'up', 'positive' => 'text-green-600 dark:text-green-400',
            'down', 'negative' => 'text-red-600 dark:text-red-400',
            default => 'text-gray-500 dark:text-gray-400',
        };

        $html = '<div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">';
        $html .= '<p class="text-sm font-medium text-gray-500 dark:text-gray-400">'.$label.'</p>';
        $html .= '<p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white">'.$value.'</p>';
        if ($change) {
            $html .= '<p class="mt-1 text-sm '.$trendClass.'">'.$change.'</p>';
        }
        $html .= '</div>';

        return $html;
    }

    private function renderAvatar(array $props): string
    {
        $src = $this->sanitizeUrl($props['src'] ?? $props['url'] ?? '');
        $name = $this->e($props['name'] ?? $props['alt'] ?? '');
        $size = match ($props['size'] ?? 'md') {
            'xs' => 'w-6 h-6 text-xs',
            'sm' => 'w-8 h-8 text-xs',
            'lg' => 'w-12 h-12 text-lg',
            'xl' => 'w-16 h-16 text-xl',
            default => 'w-10 h-10 text-sm',
        };

        if ($src) {
            return '<img src="'.$src.'" alt="'.$name.'" class="rounded-full '.$size.' object-cover" loading="lazy">';
        }

        $initials = $this->e($this->initials($props['name'] ?? ''));

        return '<span class="inline-flex items-center justify-center rounded-full bg-gray-200 dark:bg-gray-700 '.$size.' font-medium text-gray-600 dark:text-gray-300">'.$initials.'</span>';
    }

    private function renderRating(array $props): string
    {
        $value = max(0, min(5, (float) ($props['value'] ?? 0)));
        $max = (int) ($props['max'] ?? 5);
        $html = '<div class="flex items-center gap-0.5">';

        for ($i = 1; $i <= $max; $i++) {
            $filled = $i <= $value ? 'text-yellow-400' : 'text-gray-300 dark:text-gray-600';
            $html .= '<svg class="w-5 h-5 '.$filled.'" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
        }

        $html .= '</div>';

        return $html;
    }

    private function renderTabs(array $props, array $map, array $dataModel, array &$visited, int $depth): string
    {
        $tabs = $props['tabs'] ?? [];
        if (empty($tabs)) {
            return '<!-- A2UI: Tabs with no tab definitions -->';
        }

        $tabId = 'a2ui-tabs-'.md5(json_encode($tabs));
        $html = '<div x-data="{ activeTab: 0 }">';

        // Tab buttons
        $html .= '<div class="flex border-b border-gray-200 dark:border-gray-700">';
        foreach ($tabs as $i => $tab) {
            $label = $this->e($tab['label'] ?? 'Tab '.($i + 1));
            $html .= '<button @click="activeTab = '.$i.'" :class="activeTab === '.$i.' ? \'border-primary-500 text-primary-600 dark:text-primary-400\' : \'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300\'" class="px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px">'.$label.'</button>';
        }
        $html .= '</div>';

        // Tab content
        foreach ($tabs as $i => $tab) {
            $content = isset($tab['content']) ? $this->renderNode($tab['content'], $map, $dataModel, $visited, $depth + 1) : '';
            $html .= '<div x-show="activeTab === '.$i.'" class="py-4">'.$content.'</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function renderModal(array $props, array $map, array $dataModel, array &$visited, int $depth): string
    {
        $title = $this->e($props['title'] ?? '');
        $childHtml = $this->renderChildren($props, $map, $dataModel, $visited, $depth);
        $open = ! empty($props['open']);

        $html = '<div x-data="{ open: '.($open ? 'true' : 'false').' }">';
        $html .= '<div x-show="open" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">';
        $html .= '<div class="relative w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.outside="open = false">';
        if ($title) {
            $html .= '<div class="flex items-center justify-between mb-4"><h3 class="text-lg font-semibold text-gray-900 dark:text-white">'.$title.'</h3>';
            $html .= '<button @click="open = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">&times;</button></div>';
        }
        $html .= '<div>'.$childHtml.'</div>';
        $html .= '</div></div></div>';

        return $html;
    }

    private function renderTextField(array $props): string
    {
        $label = $this->e($props['label'] ?? '');
        $placeholder = $this->e($props['placeholder'] ?? '');
        $value = $this->e((string) ($props['value'] ?? ''));
        $hint = isset($props['hint']) ? $this->e($props['hint']) : null;
        $required = ! empty($props['required']);
        $type = in_array($props['type'] ?? 'text', ['text', 'email', 'password', 'number', 'tel', 'url']) ? $props['type'] : 'text';
        $name = $this->e($props['name'] ?? $props['label'] ?? 'field');

        $html = '<div>';
        if ($label) {
            $html .= '<label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">'.$label.($required ? ' <span class="text-red-500">*</span>' : '').'</label>';
        }
        $html .= '<input type="'.$type.'" name="'.$name.'" value="'.$value.'" placeholder="'.$placeholder.'" class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400 dark:focus:border-primary-500"'.($required ? ' required' : '').'>';
        if ($hint) {
            $html .= '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">'.$hint.'</p>';
        }
        $html .= '</div>';

        return $html;
    }

    private function renderCheckBox(array $props): string
    {
        $label = $this->e($props['label'] ?? '');
        $checked = ! empty($props['checked'] ?? $props['value'] ?? false);
        $name = $this->e($props['name'] ?? $props['label'] ?? 'checkbox');

        return '<label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" name="'.$name.'" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"'.($checked ? ' checked' : '').'><span class="text-sm text-gray-700 dark:text-gray-300">'.$label.'</span></label>';
    }

    private function renderChoicePicker(array $props): string
    {
        $label = $this->e($props['label'] ?? '');
        $options = $props['options'] ?? [];
        $selected = $props['value'] ?? $props['selected'] ?? '';
        $name = $this->e($props['name'] ?? $props['label'] ?? 'choice');

        $html = '<div>';
        if ($label) {
            $html .= '<label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">'.$label.'</label>';
        }
        $html .= '<select name="'.$name.'" class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">';

        foreach ($options as $option) {
            if (is_array($option)) {
                $val = $this->e((string) ($option['value'] ?? ''));
                $optLabel = $this->e($option['label'] ?? $option['value'] ?? '');
            } else {
                $val = $this->e((string) $option);
                $optLabel = $val;
            }
            $sel = ($val === (string) $selected) ? ' selected' : '';
            $html .= '<option value="'.$val.'"'.$sel.'>'.$optLabel.'</option>';
        }

        $html .= '</select></div>';

        return $html;
    }

    private function renderSlider(array $props): string
    {
        $label = $this->e($props['label'] ?? '');
        $min = (int) ($props['min'] ?? 0);
        $max = (int) ($props['max'] ?? 100);
        $value = (int) ($props['value'] ?? $min);
        $step = (int) ($props['step'] ?? 1);
        $name = $this->e($props['name'] ?? 'slider');

        $html = '<div>';
        if ($label) {
            $html .= '<div class="flex justify-between text-sm mb-1"><span class="font-medium text-gray-700 dark:text-gray-300">'.$label.'</span><span class="text-gray-500 dark:text-gray-400">'.$value.'</span></div>';
        }
        $html .= '<input type="range" name="'.$name.'" min="'.$min.'" max="'.$max.'" value="'.$value.'" step="'.$step.'" class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700 accent-primary-500">';
        $html .= '</div>';

        return $html;
    }

    private function renderDateTimeInput(array $props): string
    {
        $label = $this->e($props['label'] ?? '');
        $value = $this->e((string) ($props['value'] ?? ''));
        $type = in_array($props['type'] ?? 'date', ['date', 'time', 'datetime-local']) ? $props['type'] : 'date';
        $name = $this->e($props['name'] ?? 'date');

        $html = '<div>';
        if ($label) {
            $html .= '<label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">'.$label.'</label>';
        }
        $html .= '<input type="'.$type.'" name="'.$name.'" value="'.$value.'" class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">';
        $html .= '</div>';

        return $html;
    }

    private function renderVideo(array $props): string
    {
        $src = $this->sanitizeUrl($props['src'] ?? $props['url'] ?? '');
        if (! $src) {
            return '<!-- A2UI: Video missing src -->';
        }

        $poster = isset($props['poster']) ? ' poster="'.$this->sanitizeUrl($props['poster']).'"' : '';
        $autoplay = ! empty($props['autoplay']) ? ' autoplay muted' : '';
        $controls = ($props['controls'] ?? true) !== false ? ' controls' : '';

        return '<video src="'.$src.'" class="w-full rounded-lg"'.$poster.$controls.$autoplay.' preload="metadata"></video>';
    }

    private function renderAudioPlayer(array $props): string
    {
        $src = $this->sanitizeUrl($props['src'] ?? $props['url'] ?? '');
        if (! $src) {
            return '<!-- A2UI: AudioPlayer missing src -->';
        }

        return '<audio src="'.$src.'" controls class="w-full" preload="metadata"></audio>';
    }

    // ─── Utilities ───────────────────────────────────────────────

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Allowlist: only http, https, and relative URLs
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'])) {
            return '';
        }

        return $this->e($url);
    }

    private function sanitizeColor(string $color): string
    {
        // Allow hex colors and named CSS colors only
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) || preg_match('/^[a-zA-Z]+$/', $color)) {
            return $color;
        }

        return 'inherit';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (empty($parts) || $parts[0] === '') {
            return '?';
        }

        if (count($parts) === 1) {
            return strtoupper(mb_substr($parts[0], 0, 2));
        }

        return strtoupper(mb_substr($parts[0], 0, 1).mb_substr(end($parts), 0, 1));
    }
}
