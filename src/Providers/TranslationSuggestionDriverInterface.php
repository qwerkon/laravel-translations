<?php

namespace Outhebox\TranslationsUI\Providers;

interface TranslationSuggestionDriverInterface
{
    public function id(): string;

    public function engine(): string;

    public function icon(): string;

    public function preserveParameters(bool|string $pattern = true): self;

    public function setSource(?string $sourceLang): static;

    public function setTarget(?string $targetLang): static;

    public function translate(?string $text): string;

    public function translateMany(iterable $texts = []): array;
}
