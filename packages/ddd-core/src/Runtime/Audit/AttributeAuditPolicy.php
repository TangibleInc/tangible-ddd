<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * The default IAuditPolicy from wave 4 (D12): audit every command with its
 * (redacted) parameters, except where the command opts out.
 *
 * - `#[Audit(false)]` on the command class or a parent: no row.
 * - `#[Audit(parameters: false)]`: a row without parameters.
 * - `$notAudited` / `$withoutParameters`: class-strings matched with is_a(),
 *   so a parent class or a marker interface covers a family of commands
 *   (host configuration, e.g. TXP's heartbeat and progress commands).
 *
 * A class-list entry wins over the attribute in the "off" direction only:
 * listing a class turns its audit off even when it declares #[Audit(true)].
 *
 * CorrelationMiddleware falls back to this policy when neither its
 * constructor nor HostDefaults provides one. AuditEverything stays the
 * literal "everything" policy for hosts that bind it explicitly.
 *
 * Error behaviour: never throws. Lifetime: stateless apart from a per-class
 * reflection cache.
 */
final class AttributeAuditPolicy implements IAuditPolicy {

  /** @var array<class-string, ?Audit> */
  private array $attributes = [];

  /**
   * @param list<class-string> $notAudited        commands (or parents / interfaces) never audited
   * @param list<class-string> $withoutParameters commands audited without parameters
   */
  public function __construct(
    private readonly array $notAudited = [],
    private readonly array $withoutParameters = [],
  ) {}

  public function audits(object $command): bool {
    if ($this->listed($command, $this->notAudited)) {
      return false;
    }
    return $this->attributeOf($command)?->enabled ?? true;
  }

  public function captures_parameters(object $command): bool {
    if ($this->listed($command, $this->withoutParameters)) {
      return false;
    }
    return $this->attributeOf($command)?->parameters ?? true;
  }

  /** @param list<class-string> $classes */
  private function listed(object $command, array $classes): bool {
    foreach ($classes as $class) {
      if ($command instanceof $class) {
        return true;
      }
    }
    return false;
  }

  private function attributeOf(object $command): ?Audit {
    $class = get_class($command);
    if (array_key_exists($class, $this->attributes)) {
      return $this->attributes[$class];
    }

    $found = null;
    for ($r = new \ReflectionClass($command); $r !== false; $r = $r->getParentClass()) {
      $attrs = $r->getAttributes(Audit::class);
      if ($attrs !== []) {
        $found = $attrs[0]->newInstance();
        break;
      }
    }
    return $this->attributes[$class] = $found;
  }
}
