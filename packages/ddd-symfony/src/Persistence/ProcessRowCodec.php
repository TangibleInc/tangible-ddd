<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Domain\Shared\JsonLifecycleValue;
use TangibleDDD\Runtime\Codec\LargeString;
use TangibleDDD\Runtime\Codec\UndecodableLargeString;

/**
 * LongProcess ⇄ ddd_processes row, the same JSON shapes as the WordPress
 * ProcessRepository (business_data = the promoted constructor parameters,
 * steps = ProcessSteps::to_array(), payload and await_mechanism =
 * polymorphic {_class, _data}), so a process row reads the same on every
 * host. decode() throws \UnexpectedValueException for anything it cannot
 * rebuild; the store turns that into a quarantine (R5).
 *
 * D6 (CR-W4CE-5): a LargeString business-data field is stored as
 * LargeString::encode() and revived by the constructor parameter's type;
 * a corrupt one (UndecodableLargeString) quarantines the row with its
 * reason. Process payloads are not scanned for LargeString.
 *
 * @internal
 */
final class ProcessRowCodec {

  private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

  /** @return array<string, mixed> the state columns (no id, version, ignition_key or timestamps) */
  public static function encode(LongProcess $p): array {
    $steps = $p->steps();
    $payload = $p->payload();
    $mechanism = $p->await_mechanism();

    return [
      'process_class' => get_class($p),
      'business_data' => json_encode(self::businessData($p), self::JSON),
      'steps' => $steps === null ? null : json_encode($steps->to_array(), self::JSON),
      'step_index' => $p->current_step_index(),
      'step_name' => $p->current_step_name(),
      'status' => $p->status(),
      'waiting_for' => $p->waiting_for(),
      'match_criteria' => $p->match_criteria() === null ? null : json_encode($p->match_criteria(), self::JSON),
      'await_mechanism' => $mechanism === null ? null : json_encode(['_class' => get_class($mechanism), '_data' => $mechanism->to_array()], self::JSON),
      'payload' => $payload === null ? null : json_encode(JsonLifecycleValue::serialize_polymorphic($payload), self::JSON),
      'correlation_id' => $p->correlation_id(),
      'ignited_by_event_id' => $p->ignited_by_event_id(),
      'source' => $p->source(),
      'last_error' => $p->last_error(),
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @throws \UnexpectedValueException
   */
  public static function decode(array $row): LongProcess {
    $class = (string) $row['process_class'];
    if (!class_exists($class)) {
      throw new \UnexpectedValueException("stored class $class does not exist");
    }
    if (!is_subclass_of($class, LongProcess::class)) {
      throw new \UnexpectedValueException("stored class $class is not a LongProcess");
    }

    try {
      $process = self::instantiate($class, self::json((string) $row['business_data']) ?? []);

      // Arrays, not stdClass: ProcessSteps::checkpoint_for() hands each stored
      // checkpoint to JsonLifecycleValue::deserialize_polymorphic(?array).
      $steps = $row['steps'] === null ? null : ProcessSteps::from_json(self::json((string) $row['steps']) ?? []);
      $payload = $row['payload'] === null ? null : JsonLifecycleValue::deserialize_polymorphic(self::json((string) $row['payload']));
      $criteria = $row['match_criteria'] === null ? null : self::json((string) $row['match_criteria']);

      $mechanism = null;
      if ($row['await_mechanism'] !== null) {
        $decoded = self::json((string) $row['await_mechanism']) ?? [];
        $mclass = $decoded['_class'] ?? null;
        if (!is_string($mclass) || !is_a($mclass, IAwaitMechanism::class, true)) {
          throw new \UnexpectedValueException('stored await mechanism class ' . var_export($mclass, true) . ' is not an IAwaitMechanism');
        }
        $mechanism = $mclass::from_array((array) ($decoded['_data'] ?? []));
      } elseif ($row['waiting_for'] !== null && $row['status'] === 'suspended') {
        $mechanism = new AwaitEvent((string) $row['waiting_for'], $criteria ?? []);
      }

      $process->hydrate(
        id: (int) $row['id'],
        status: (string) $row['status'],
        correlation_id: (string) $row['correlation_id'],
        steps: $steps,
        payload: $payload,
        waiting_for: $row['waiting_for'] === null ? null : (string) $row['waiting_for'],
        match_criteria: $criteria,
        last_error: $row['last_error'] === null ? null : (string) $row['last_error'],
        created_at: Time::from_db_or_null($row['created_at'] === null ? null : (string) $row['created_at']),
        updated_at: Time::from_db_or_null($row['updated_at'] === null ? null : (string) $row['updated_at']),
        await_mechanism: $mechanism,
        ignited_by_event_id: $row['ignited_by_event_id'] === null ? null : (string) $row['ignited_by_event_id'],
        source: $row['source'] === null ? null : (string) $row['source'],
      );
      return $process;
    } catch (UndecodableLargeString $e) {
      throw new \UnexpectedValueException(sprintf('%s cannot be rebuilt: %s', $class, $e->reason), 0, $e);
    } catch (\UnexpectedValueException $e) {
      throw $e;
    } catch (\Throwable $e) {
      throw new \UnexpectedValueException(sprintf('%s cannot be rebuilt: %s', $class, $e->getMessage()), 0, $e);
    }
  }

  /** @return array<string, mixed> */
  private static function businessData(LongProcess $p): array {
    $data = [];
    $reflection = new \ReflectionClass($p);
    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
      if ($param->isPromoted()) {
        $value = $reflection->getProperty($param->getName())->getValue($p);
        // D6: a LargeString is stored in its wire form (base64, length, sha256).
        $data[$param->getName()] = $value instanceof LargeString ? $value->encode() : $value;
      }
    }
    return $data;
  }

  /**
   * D6: a constructor parameter typed LargeString (nullable or not) is
   * revived from its wire form. A corrupt one throws UndecodableLargeString;
   * decode() turns its reason into the quarantine reason.
   *
   * @throws UndecodableLargeString
   */
  private static function revive(\ReflectionParameter $param, mixed $value): mixed {
    $type = $param->getType();
    if ($value !== null && $type instanceof \ReflectionNamedType && $type->getName() === LargeString::class) {
      return LargeString::decode($value);
    }
    return $value;
  }

  /** @param array<string, mixed> $data */
  private static function instantiate(string $class, array $data): LongProcess {
    $reflection = new \ReflectionClass($class);
    $constructor = $reflection->getConstructor();
    if ($constructor === null) {
      return $reflection->newInstance();
    }
    $args = [];
    foreach ($constructor->getParameters() as $param) {
      $name = $param->getName();
      if (array_key_exists($name, $data)) {
        $args[] = self::revive($param, $data[$name]);
      } elseif ($param->isDefaultValueAvailable()) {
        $args[] = $param->getDefaultValue();
      } else {
        throw new \UnexpectedValueException("missing required constructor parameter '$name' for $class");
      }
    }
    return $reflection->newInstanceArgs($args);
  }

  /** @return ?array<mixed> */
  private static function json(string $text): ?array {
    $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : null;
  }
}
