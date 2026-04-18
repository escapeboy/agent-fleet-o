<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;
use App\Domain\Assistant\Artifacts\Support\UrlValidator;

final class LinkListArtifact extends BaseArtifact
{
    public const TYPE = 'link_list';

    private const MAX_ITEMS = 10;

    private const MAX_TITLE_CHARS = 100;

    private const MAX_LABEL_CHARS = 200;

    private const MAX_DESCRIPTION_CHARS = 300;

    /**
     * @param  list<array{label: string, url: string, description: ?string}>  $items
     */
    public function __construct(
        public readonly ?string $title,
        public readonly array $items,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?static
    {
        $title = StringSanitizer::clean($raw['title'] ?? null, self::MAX_TITLE_CHARS);

        $rawItems = $raw['items'] ?? [];
        if (! is_array($rawItems)) {
            return null;
        }

        $items = [];
        foreach (array_slice($rawItems, 0, self::MAX_ITEMS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = UrlValidator::normalize($item['url'] ?? null);
            if ($url === null) {
                continue;
            }

            $label = StringSanitizer::clean($item['label'] ?? null, self::MAX_LABEL_CHARS);
            if ($label === null) {
                continue;
            }

            $description = StringSanitizer::clean($item['description'] ?? null, self::MAX_DESCRIPTION_CHARS);

            $items[] = [
                'label' => $label,
                'url' => $url,
                'description' => $description,
            ];
        }

        if ($items === []) {
            return null;
        }

        return new self(title: $title, items: $items);
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'title' => $this->title,
            'items' => $this->items,
        ];
    }
}
