<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use stdClass;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;

/** A behaviour config for the D10 store tests (type `sf_stop`). */
final class StopBehaviourConfig extends BaseBehaviourConfig {

  public function __construct(public readonly string $reason = '') {
    parent::__construct();
  }

  public static function register(): void {
    BaseBehaviourConfig::register_type('sf_stop', self::class);
  }

  public function get_behaviour_type(): string {
    return 'sf_stop';
  }

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    $d = (array) $rendered_data;
    return new static((string) ($d['reason'] ?? ''));
  }
}
