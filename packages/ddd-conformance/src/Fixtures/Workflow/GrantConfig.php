<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Workflow;

use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;

/** The one behaviour of GrantWorkflow (workflow.item-deterministic-id); no settings. */
final class GrantConfig extends BaseBehaviourConfig {

  public const TYPE = 'conformance_grant';

  public function get_behaviour_type(): string {
    return self::TYPE;
  }

  protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static {
    return new static();
  }
}
