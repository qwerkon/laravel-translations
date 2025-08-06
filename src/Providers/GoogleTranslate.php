<?php

namespace Outhebox\TranslationsUI\Providers;

class GoogleTranslate implements TranslationSuggestionDriverInterface
{
    protected string $source;
    protected string $target;

    public function id(): string
    {
        return 'google';
    }

    public function icon(): string
    {
        return 'google';
    }

    public function engine(): string
    {
        return 'Google Translate';
    }

    public function preserveParameters(bool|string $pattern = true): self
    {
        if ($pattern === true) {
            $this->pattern = '/:(\w+)/'; // e.g. ":name"
        } elseif ($pattern === false) {
            $this->pattern = null;
        } elseif (is_string($pattern)) {
            $this->pattern = $pattern;
        }

        return $this;
    }

    public function setSource(?string $sourceLang): static
    {
        $this->source = $sourceLang;
        return $this;
    }

    public function setTarget(?string $targetLang): static
    {
        $this->target = $targetLang;
        return $this;
    }

    public function translate(?string $text = null): string
    {
        return (new \Stichoza\GoogleTranslate\GoogleTranslate)
            ->preserveParameters()
            ->setSource($this->source)
            ->setTarget($this->target)
            ->translate($text);
    }

    public function translateMany(iterable $texts = []): array
    {
        $results = [];

        foreach ($texts as $key => $text) {
            try {
                $results[$key] = $this->translate($text);
            } catch (Throwable $e) {
                report($e);
                $results[$key] = null;
            }
        }

        return $results;
    }
}
