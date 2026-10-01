<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Workflows;

use stdClass;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;

/**
 * W2: a behaviour config found by the app's resource loading. The bundle
 * registers its type at boot (autoconfigured), so nothing calls
 * register_type() by hand. Type `sfk_digest_step` is used by no other test.
 */
final class DigestStepConfig extends BaseBehaviourConfig {

  public function __construct(public readonly int $size = 10) {
    parent::__construct();
  }

  public function get_behaviour_type(): string {
    return 'sfk_digest_step';
  }

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    return new static((int) (((array) $rendered_data)['size'] ?? 10));
  }
}
