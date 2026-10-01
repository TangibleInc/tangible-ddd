<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Domain\Shared\JsonLifecycleValue;
use TangibleDDD\Runtime\Codec\LargeString;
use TangibleDDD\Runtime\Codec\UndecodableLargeString;

/**
 * LongProcess ↔ `ddd_processes` row, in the same column format as the wp
 * `long_processes` table (business_data = promoted constructor parameters,
 * steps = ProcessSteps JSON, payload = polymorphic {_class, _data},
 * await_mechanism = {_class, _data}), so a row reads the same on every host.
 *
 * decode() throws \UnexpectedValueException with a human-readable reason
 * when the row cannot become a process (class gone, not a LongProcess,
 * constructor arguments missing, corrupt JSON, a LargeString that fails its
 * length/sha256 check); the store quarantines it.
 *
 * D6 (wave 4, CR-W4CE-5): a promoted constructor parameter holding a
 * LargeString is stored as LargeString::encode() and revived by the
 * parameter's type; UndecodableLargeString::$reason becomes the
 * quarantine reason.
 *
 * @internal
 */
final class ProcessCodec {

  /** @return array<string, mixed> the state columns (no id, version, ignition or timestamps) */
  public static function encode(LongProcess $process): array {
    $steps = $process->steps();
    $payload = $process->payload();
    $mechanism = $process->await_mechanism();
    return [
      'process_class' => get_class($process),
      'business_data' => self::json(self::businessData($process)),
      'steps' => $steps === null ? null : self::json($steps->to_array()),
      'step_index' => $process->current_step_index(),
      'step_name' => $process->current_step_name(),
      'status' => $process->status(),
      'waiting_for' => $process->waiting_for(),
      'match_criteria' => $process->match_criteria() ? self::json($process->match_criteria()) : null,
      'await_mechanism' => $mechanism === null ? null : self::json(['_class' => get_class($mechanism), '_data' => $mechanism->to_array()]),
      'payload' => $payload === null ? null : self::json(JsonLifecycleValue::serialize_polymorphic($payload)),
      'correlation_id' => $process->correlation_id(),
      'ignited_by_event_id' => $process->ignited_by_event_id(),
      'source' => $process->source(),
      'last_error' => $process->last_error(),
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @throws \UnexpectedValueException
   */
  public static function decode(array $row): LongProcess {
    $class = (string) $row['process_class'];
    if (!class_exists($class)) {
      throw new \UnexpectedValueException("stored class $class no longer exists");
    }
    if (!is_subclass_of($class, LongProcess::class)) {
      throw new \UnexpectedValueException("stored class $class is not a LongProcess");
    }

    try {
      $process = self::instantiate($class, (array) (self::decodeJson($row['business_data']) ?? []));

      $steps = $row['steps'] === null ? null : self::decodeSteps((string) $row['steps']);
      $payload = $row['payload'] === null ? null : JsonLifecycleValue::deserialize_polymorphic((array) self::decodeJson($row['payload']));
      $match = $row['match_criteria'] === null ? null : (array) self::decodeJson($row['match_criteria']);

      $mechanism = null;
      if ($row['await_mechanism'] !== null) {
        $decoded = (array) self::decodeJson($row['await_mechanism']);
        $mclass = $decoded['_class'] ?? null;
        if (is_string($mclass) && is_a($mclass, IAwaitMechanism::class, true)) {
          $mechanism = $mclass::from_array((array) ($decoded['_data'] ?? []));
        }
      } elseif ($row['waiting_for'] !== null && $row['status'] === 'suspended') {
        $mechanism = new AwaitEvent((string) $row['waiting_for'], $match ?? []);
      }
    } catch (UndecodableLargeString $e) {
      throw new \UnexpectedValueException("row of $class cannot be decoded: " . $e->reason, 0, $e);
    } catch (\Throwable $e) {
      throw new \UnexpectedValueException("row of $class cannot be decoded: " . $e->getMessage(), 0, $e);
    }

    $process->hydrate(
      id: (int) $row['id'],
      status: (string) $row['status'],
      correlation_id: (string) $row['correlation_id'],
      steps: $steps,
      payload: $payload,
      waiting_for: $row['waiting_for'] === null ? null : (string) $row['waiting_for'],
      match_criteria: $match,
      last_error: $row['last_error'] === null ? null : (string) $row['last_error'],
      created_at: Utc::from_db_or_null($row['created_at']),
      updated_at: Utc::from_db_or_null($row['updated_at']),
      await_mechanism: $mechanism,
      ignited_by_event_id: $row['ignited_by_event_id'] === null ? null : (string) $row['ignited_by_event_id'],
      source: $row['source'] === null ? null : (string) $row['source'],
    );
    return $process;
  }

  /** @return array<string, mixed> */
  private static function businessData(LongProcess $process): array {
    $data = [];
    $constructor = (new \ReflectionClass($process))->getConstructor();
    foreach ($constructor?->getParameters() ?? [] as $param) {
      if ($param->isPromoted()) {
        $value = (new \ReflectionProperty($process, $param->getName()))->getValue($process);
        // D6: a LargeString is stored in its wire form (base64 + length + sha256).
        $data[$param->getName()] = $value instanceof LargeString ? $value->encode() : $value;
      }
    }
    return $data;
  }

  /** @param class-string<LongProcess> $class @param array<string, mixed> $data */
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

  /**
   * D6: a constructor parameter typed LargeString (nullable or not) is
   * revived from its wire form; a corrupt one throws UndecodableLargeString,
   * which decode() turns into the quarantine reason.
   */
  private static function revive(\ReflectionParameter $param, mixed $value): mixed {
    $type = $param->getType();
    if ($type instanceof \ReflectionNamedType && $type->getName() === LargeString::class && $value !== null) {
      return LargeString::decode($value);
    }
    return $value;
  }

  /**
   * The steps column, decoded as the runner wrote it. JSON gives every
   * checkpoint back as an object, but ProcessSteps::checkpoint_for() takes
   * the polymorphic envelope as an array (`{_class, _data}`, as
   * serialize_polymorphic() builds it); the envelope is made an array
   * again, its `_data` is left as decoded (JsonLifecycleValue::from_json()
   * takes either). Without this a stored checkpoint (D3: the checkpoint of
   * a suspending step, W4P behaviour change 2) cannot be read back.
   */
  private static function decodeSteps(string $json): ProcessSteps {
    $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    if ($data instanceof \stdClass && isset($data->checkpoints) && (is_object($data->checkpoints) || is_array($data->checkpoints))) {
      $checkpoints = [];
      foreach ((array) $data->checkpoints as $step => $checkpoint) {
        $checkpoints[$step] = $checkpoint instanceof \stdClass ? (array) $checkpoint : $checkpoint;
      }
      $data->checkpoints = $checkpoints;
    }
    return ProcessSteps::from_json($data);
  }

  private static function decodeJson(mixed $value): mixed {
    return $value === null ? null : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
  }

  private static function json(mixed $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }
}
