<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use Doctrine\DBAL\Connection as DbalConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as MessengerDoctrineConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

/**
 * The DBAL connection a Messenger Doctrine transport writes through.
 *
 * Messenger exposes no accessor, so this reads DoctrineTransport::$connection
 * and Connection::$driverConnection by reflection. Any failure (a different
 * transport class, a renamed property) returns null, which makes the relay
 * fall back to at-least-once hand-off (submit, then accept) instead of the
 * one-transaction hand-off. The kernel suite asserts the positive case, so a
 * Messenger upgrade that breaks this shows up as a test failure.
 *
 * @internal
 */
final class DoctrineTransportConnection {

  public static function of(object $transport): ?DbalConnection {
    if (!$transport instanceof DoctrineTransport) {
      return null;
    }
    try {
      $messengerConnection = self::read($transport, 'connection');
      if (!$messengerConnection instanceof MessengerDoctrineConnection) {
        return null;
      }
      $dbal = self::read($messengerConnection, 'driverConnection');
      return $dbal instanceof DbalConnection ? $dbal : null;
    } catch (\Throwable) {
      return null;
    }
  }

  private static function read(object $object, string $property): mixed {
    $class = new \ReflectionClass($object);
    while (!$class->hasProperty($property) && ($parent = $class->getParentClass()) !== false) {
      $class = $parent;
    }
    return $class->getProperty($property)->getValue($object);
  }
}
