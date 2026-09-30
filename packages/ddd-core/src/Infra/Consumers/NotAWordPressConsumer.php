<?php

declare(strict_types=1);

namespace TangibleDDD\Infra\Consumers;

use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;

/**
 * Thrown by ConsumerHandle::config() (and so ConsumerRegistry::config_for())
 * when the consumer registered a portable IConsumerIdentity that is not an
 * IDDDConfig (register 1.4, 3.1). WordPress call sites keep calling config(),
 * which is safe because every WordPress consumer registers an IDDDConfig;
 * portable callers use ConsumerHandle::identity().
 */
final class NotAWordPressConsumer extends \LogicException {

  public static function for_identity(IConsumerIdentity $identity): self {
    return new self(sprintf(
      'DDD consumer "%s" registered a %s, not an %s; call identity() for the portable surface.',
      $identity->prefix(),
      get_class($identity),
      IDDDConfig::class,
    ));
  }
}
