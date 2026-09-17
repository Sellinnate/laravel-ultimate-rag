<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Eloquent;

use Sellinnate\RagEngine\Contracts\Embeddable;

/**
 * The flattened result of compiling an {@see Embeddable}
 * (and its related embeddables) into a single indexable document.
 */
final class CompiledEmbeddable
{
    /**
     * @param  array<string, mixed>  $metadata  Provenance metadata for the document.
     * @param  list<string>  $includedKeys  Identity keys of every nested embeddable composed in.
     * @param  array<string, mixed>  $options  Per-model chunking/indexing options.
     * @param  string|null  $title  The root model's declared title (see EmbeddableDefinition::title()).
     */
    public function __construct(
        public readonly string $content,
        public readonly array $metadata,
        public readonly array $includedKeys,
        public readonly array $options,
        public readonly string $documentKey,
        public readonly ?string $title = null,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }

    public function checksum(): string
    {
        return hash('sha256', $this->content);
    }
}
