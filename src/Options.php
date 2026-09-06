<?php

declare(strict_types=1);

namespace WeewxPhp\Extensions\Forecast;

use WeewxPhp\Config\Section;

final class Options
{
    public readonly bool $enabled;
    public readonly int $days;
    public readonly int $every;
    public readonly int $timeout;
    public readonly string $apiKey;

    public function __construct(Section $options)
    {
        try {
            $this->enabled = $options->optional('enabled')?->bool() ?? true;
            $this->days = $options->optional('days')?->int() ?? 7;
            $this->every = $options->optional('every')?->duration() ?? 3600;
            $this->timeout = $options->optional('timeout')?->int() ?? 8;
            $this->apiKey = $options->optional('api_key')?->string() ?? '';
        } catch (\WeewxPhp\Config\ConfigError) {
            throw new \InvalidArgumentException('Invalid forecast options');
        }
        if ($this->days < 1 || $this->days > 16 || $this->every < 1800 || $this->every > 86400 || $this->timeout < 1 || $this->timeout > 30
            || ($this->apiKey !== '' && preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $this->apiKey) !== 1)) {
            throw new \InvalidArgumentException('Invalid forecast options');
        }
    }
}
