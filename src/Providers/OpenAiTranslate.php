<?php

namespace Outhebox\TranslationsUI\Providers;

use LanguageDetection\Language;
use OpenAI\Contracts\ClientContract;
use OpenAI;
use Throwable;

class OpenAiTranslate implements TranslationSuggestionDriverInterface
{
    protected ?string $sourceLang = null;
    protected ?string $targetLang = 'en';
    protected ?string $lastDetectedSource = null;
    protected ?string $pattern = null;

    public function id(): string
    {
        return 'openai';
    }

    public function icon(): string
    {
        return 'openai';
    }

    public function engine(): string
    {
        return 'OpenAI Translate';
    }

    public function __construct(
        ?string     $targetLang = 'en',
        ?string     $sourceLang = null,
        bool|string $preserveParameters = true,
    )
    {
        $this->setTarget($targetLang);
        $this->setSource($sourceLang);
        $this->preserveParameters($preserveParameters);
    }

    public function setTarget(?string $targetLang): static
    {
        $this->targetLang = $targetLang ?? 'en';
        return $this;
    }

    public function setSource(?string $sourceLang): static
    {
        $this->sourceLang = $sourceLang;
        return $this;
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

    public function translate(?string $text): string
    {
        if (!config('services.openai.api_key')) {
            return 'Please configure OpenAI API key';
        }

        $replacements = $this->getParameters($text);
        $input = $this->extractParameters($text);

        $prompt = $this->buildPrompt($input);

        $response = $this->openAiClient()->chat()->create([
            'model' => config('services.openai.default_model', 'gpt-4o'),
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ]
            ]
        ]);

        $result = trim($response->choices[0]->message->content ?? '');

        $this->lastDetectedSource = $this->sourceLang ?: $this->detectLangFromOutput($result);

        return $this->pattern
            ? $this->injectParameters($result, $replacements)
            : $result;
    }

    protected function getParameters(string $text): array
    {
        if (!$this->pattern) {
            return [];
        }

        preg_match_all($this->pattern, $text, $matches);
        return $matches[0] ?? [];
    }

    protected function extractParameters(string $text): string
    {
        if (!$this->pattern) {
            return $text;
        }

        return preg_replace_callback(
            pattern: $this->pattern,
            callback: static function ($matches) {
                static $index = 0;
                return '#{' . $index++ . '}';
            },
            subject: $text
        );
    }

    protected function buildPrompt(string $text): string
    {
        $source = $this->sourceLang ?? 'auto';

        return <<<PROMPT
Translate the following text from "{$source}" to "{$this->targetLang}".
Return only the translated text without quotes or markdown formatting.

Text:
{$text}
PROMPT;
    }

    protected function openAiClient(): ClientContract
    {
        return OpenAI::client(
            config('services.openai.api_key'),
            config('services.openai.organization_id'),
            config('services.openai.project_id')
        );
    }

    protected function systemPrompt(): string
    {
        return <<<SYSTEM
You are a professional translator. You always preserve parameters like :name or #{0} during translation.
Avoid hallucinations. Never wrap the translation in quotes or formatting.
SYSTEM;
    }

    protected function detectLangFromOutput(string $text): ?string
    {
        $cacheKey = 'lang_detect:' . md5($text);

        return cache()->remember($cacheKey, now()->addDays(30), function () use ($text) {
            // 1. OpenAI
            try {
                $lang = strtolower(trim(
                    $this->openAiClient()->chat()->create([
                        'model' => 'gpt-4o',
                        'temperature' => 0,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Return only the ISO 639-1 language code (e.g. "pl", "en", "de") of the given text. No explanation.',
                            ],
                            [
                                'role' => 'user',
                                'content' => $text,
                            ],
                        ]
                    ])->choices[0]->message->content ?? ''
                ));

                if (preg_match('/^[a-z]{2}$/', $lang)) {
                    return $lang;
                }
            } catch (Throwable $e) {
                report($e);
            }

            try {
                $ld = new Language;
                $best = $ld->detect($text)->bestResults()->close();
                return array_key_first($best);
            } catch (Throwable $e) {
                report($e);
            }

            return null;
        });
    }

    public function detect(string $text): ?string
    {
        $this->setSource(null)->translate($text);
        return $this->getLastDetectedSource();
    }

    public function getLastDetectedSource(): ?string
    {
        return $this->lastDetectedSource;
    }

    protected function injectParameters(string $text, array $replacements): string
    {
        return preg_replace_callback(
            pattern: '/\#{(\d+)}/',
            callback: static fn($matches) => $replacements[(int)$matches[1]] ?? '',
            subject: $text
        );
    }
}
