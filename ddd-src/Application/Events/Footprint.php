<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\Touches;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;

/**
 * The declared harvest (spec appendix 9): projects an event's #[Touches]
 * attributes into at-rest entries — the write-side sibling of the events
 * list the audit row already carries.
 *
 *   [ ['aggregate' => 'cred.license', 'id' => '4021', 'op' => 'created'], ... ]
 *
 * Class refs live in domain code; canonical STRINGS exist only here, at the
 * boundary: owner_of() supplies the prefix, Aggregate::canonical_name() the
 * local name. The subject id comes from the attribute's `id:` ctor-param
 * name, else the `{canonical_name}_id` convention.
 *
 * NEVER THROWS — this runs post-commit inside the act bracket's finalise
 * (the JLV ruling: read-side decoration never throws). Bad declarations are
 * logged and skipped; the conformance scan is the hard gate that fails CI.
 */
final class Footprint {

  /** @return list<array{aggregate: string, id: string, op: string}> */
  public static function of_event(object $event): array {
    $entries = [];

    try {
      $attributes = (new \ReflectionClass($event))->getAttributes(Touches::class);
    } catch (\Throwable $e) {
      return [];
    }

    foreach ($attributes as $attribute) {
      try {
        $touches = $attribute->newInstance();

        $local = $touches->aggregate::canonical_name();
        $aggregate = ConsumerRegistry::owner_of($touches->aggregate)->prefix() . '.' . $local;

        $param = $touches->id ?? $local . '_id';
        $ref = new \ReflectionClass($event);
        if (!$ref->hasProperty($param) || !$ref->getProperty($param)->isPublic()) {
          throw new \LogicException(sprintf(
            '%s touches %s but has no public "%s" property to carry the subject id.',
            get_class($event),
            $aggregate,
            $param
          ));
        }

        $entries[] = [
          'aggregate' => $aggregate,
          'id' => (string) $event->{$param},
          'op' => $touches->op->value,
        ];
      } catch (\Throwable $e) {
        error_log(sprintf(
          '[DDD Touches] skipped a declaration on %s: %s',
          get_class($event),
          $e->getMessage()
        ));
      }
    }

    return $entries;
  }
}
