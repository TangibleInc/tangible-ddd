<?php

namespace TangibleDDD\Infra\Persistence;

use DateTimeImmutable;
use ReflectionClass;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Domain\Shared\JsonLifecycleValue;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Runtime\Codec\LargeString;
use TangibleDDD\Runtime\Codec\UndecodableLargeString;

/**
 * MySQL/WordPress implementation of the process repository.
 *
 * Stores LongProcess state as structured JSON columns:
 * - business_data: child class constructor params
 * - steps: ProcessSteps state
 * - payload: polymorphic {_class, _data} format
 */
class ProcessRepository implements IProcessRepository {

  public function __construct(
    private readonly IDDDConfig $config
  ) {}

  public function save(LongProcess $process): int {
    global $wpdb;

    $row = $this->row_for($process);

    if ($process->get_id() === null) {
      $row['created_at'] = $row['updated_at'];
      $wpdb->insert($this->table_name(), $row);
      $id = (int) $wpdb->insert_id;
      $process->set_id($id);
      return $id;
    }

    $wpdb->update(
      $this->table_name(),
      $row,
      ['id' => $process->get_id()]
    );

    return $process->get_id();
  }

  /**
   * The 0.6 column values for $process (every column save() writes except
   * created_at), for the schema v8 store (WpdbProcessStore), which adds
   * version / ignition_key and writes them in its own fenced statements.
   *
   * @return array<string, mixed>
   */
  public function row_for(LongProcess $process): array {
    $now = gmdate('Y-m-d H:i:s');
    $steps = $process->steps();
    $payload = $process->payload();

    return [
      'process_class' => get_class($process),
      'business_data' => wp_json_encode($this->extract_business_data($process)),
      'steps' => $steps ? wp_json_encode($steps->to_array()) : null,
      'step_index' => $process->current_step_index(),
      'step_name' => $process->current_step_name(),
      'status' => $process->status(),
      'waiting_for' => $process->waiting_for(),
      'match_criteria' => $process->match_criteria() ? wp_json_encode($process->match_criteria()) : null,
      'await_mechanism' => $process->await_mechanism()
        ? wp_json_encode([
            '_class' => get_class($process->await_mechanism()),
            '_data'  => $process->await_mechanism()->to_array(),
          ])
        : null,
      'payload' => $payload ? wp_json_encode(JsonLifecycleValue::serialize_polymorphic($payload)) : null,
      'correlation_id' => $process->correlation_id(),
      'ignited_by_event_id' => $process->ignited_by_event_id(),
      'source' => $process->source(),
      'last_error' => $process->last_error(),
      'updated_at' => $now,
      'blog_id' => is_multisite() ? get_current_blog_id() : 1,
    ];
  }

  /**
   * Rebuild a process from one `long_processes` row (the 0.6 hydration),
   * for the schema v8 store. Throws when the row cannot be decoded (the
   * class no longer exists, a constructor parameter is missing, …).
   */
  public function hydrate_row(object $row): LongProcess {
    return $this->hydrate_from_row($row);
  }

  public function find(int $id): ?LongProcess {
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}` WHERE id = %d",
      $id
    ));

    if (!$row) {
      return null;
    }

    return $this->hydrate_from_row($row);
  }

  public function has_ignition(string $process_class, string $event_id): bool {
    global $wpdb;

    return (bool) $wpdb->get_var($wpdb->prepare(
      "SELECT 1 FROM `{$this->table_name()}`
        WHERE process_class = %s AND ignited_by_event_id = %s
        LIMIT 1",
      $process_class,
      $event_id
    ));
  }

  public function find_waiting_for(string $event_class): array {
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}`
       WHERE waiting_for = %s
         AND status = 'suspended'",
      $event_class
    ));

    return array_map(fn($row) => $this->hydrate_from_row($row), $rows);
  }

  public function delete(int $id): void {
    global $wpdb;

    $wpdb->delete($this->table_name(), ['id' => $id]);
  }

  private function table_name(): string {
    return $this->config->table('long_processes');
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Serialization
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Extract child class constructor params via reflection.
   */
  private function extract_business_data(LongProcess $process): array {
    $data = [];
    $reflection = new ReflectionClass($process);
    $constructor = $reflection->getConstructor();

    if ($constructor === null) {
      return $data;
    }

    foreach ($constructor->getParameters() as $param) {
      if ($param->isPromoted()) {
        $prop = $reflection->getProperty($param->getName());
        $prop->setAccessible(true);
        $value = $prop->getValue($process);
        // D6: a LargeString is stored in its wire form (base64 + length +
        // sha256), never as its raw bytes, which JSON cannot carry.
        $data[$param->getName()] = $value instanceof LargeString ? $value->toPayload() : $value;
      }
    }

    return $data;
  }

  /**
   * Reconstruct process from database row.
   */
  private function hydrate_from_row(object $row): LongProcess {
    $class = $row->process_class;
    $business_data = json_decode($row->business_data, true) ?? [];

    // Create instance via reflection with constructor params
    $process = $this->create_instance($class, $business_data);

    // Restore steps
    $steps = $row->steps
      ? ProcessSteps::from_json(json_decode($row->steps))
      : null;

    // Restore payload (polymorphic)
    $payload = $row->payload
      ? JsonLifecycleValue::deserialize_polymorphic(json_decode($row->payload, true))
      : null;

    // Restore await mechanism (with legacy fallback for pre-0.2.0 rows)
    $mechanism = null;
    if (!empty($row->await_mechanism ?? null)) {
      $decoded = json_decode($row->await_mechanism, true);
      $mclass = $decoded['_class'] ?? null;
      if ($mclass && is_a($mclass, \TangibleDDD\Application\Process\IAwaitMechanism::class, true)) {
        $mechanism = $mclass::from_array($decoded['_data'] ?? []);
      }
    } elseif ($row->waiting_for && $row->status === 'suspended') {
      // Legacy row from before 0.2.0 — reconstruct the 1-of-1 mechanism.
      $mechanism = new \TangibleDDD\Application\Process\AwaitEvent(
        $row->waiting_for,
        $row->match_criteria ? (json_decode($row->match_criteria, true) ?? []) : []
      );
    }

    // Hydrate all framework state
    $process->hydrate(
      id: (int) $row->id,
      status: $row->status,
      correlation_id: $row->correlation_id,
      steps: $steps,
      payload: $payload,
      waiting_for: $row->waiting_for,
      match_criteria: $row->match_criteria ? json_decode($row->match_criteria, true) : null,
      last_error: $row->last_error,
      created_at: $row->created_at ? new DateTimeImmutable($row->created_at) : null,
      updated_at: $row->updated_at ? new DateTimeImmutable($row->updated_at) : null,
      await_mechanism: $mechanism,
      ignited_by_event_id: $row->ignited_by_event_id ?? null,
      source: $row->source ?? null,
    );

    return $process;
  }

  /**
   * Create process instance via reflection.
   */
  private function create_instance(string $class, array $data): LongProcess {
    $reflection = new ReflectionClass($class);
    $constructor = $reflection->getConstructor();

    if ($constructor === null) {
      return $reflection->newInstance();
    }

    $args = [];
    foreach ($constructor->getParameters() as $param) {
      $name = $param->getName();
      if (array_key_exists($name, $data)) {
        $args[] = self::revive($class, $param, $data[$name]);
      } elseif ($param->isDefaultValueAvailable()) {
        $args[] = $param->getDefaultValue();
      } else {
        throw new \RuntimeException("Missing required constructor parameter '$name' for $class");
      }
    }

    return $reflection->newInstanceArgs($args);
  }

  /**
   * D6: a constructor parameter typed LargeString (nullable or not) is
   * revived from its wire form. A corrupt one (bad base64, length or sha256
   * mismatch, over its cap) throws, naming the field and
   * UndecodableLargeString::$quarantineReason, so the v8 store quarantines
   * the row with that reason (status `failed`, R5).
   */
  private static function revive(string $class, \ReflectionParameter $param, mixed $value): mixed {
    $type = $param->getType();
    if ($value === null || !$type instanceof \ReflectionNamedType || $type->getName() !== LargeString::class) {
      return $value;
    }
    try {
      return LargeString::fromPayload($value);
    } catch (UndecodableLargeString $e) {
      throw new \UnexpectedValueException(sprintf('%s::$%s is an undecodable LargeString: %s', $class, $param->getName(), $e->quarantineReason), 0, $e);
    }
  }
}
