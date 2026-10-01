<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Conformance\ScenarioRows;
use TangibleDDD\Runtime\ITransactionBoundary;

/**
 * What the conformance processes did, in this php process: the steps that
 * ran and the step commands sent (with the deterministic command id each
 * was sent under, CR-W3C-6). Process stores hand back copies, so the
 * journal is static, as in the core runner suite.
 *
 * Durable form: once a host calls bind() with its ScenarioRows (and
 * boundary), every StepCommand also commits a row `cmd:{label}:{command id}`
 * in its own transaction, idempotently (a re-send under the same id adds
 * nothing, as a handler keyed by command id would). That is how scenarios
 * see step effects made in another php process (pdo, wp, sf fresh-process
 * runs): the row is the step's committed effect.
 */
final class ProcessJournal {

  /** @var list<string> step method names (with a `:{widget}` suffix where the step adds one), in run order */
  public static array $steps = [];

  /** @var list<array{label: string, commandId: ?string, widget: string}> `widget` is StepCommand::$widget_id (a minted ref for the D3 processes) */
  public static array $sent = [];

  /** @var array<string, true> rows marked while no ScenarioRows is bound (mark_row) */
  private static array $unboundRows = [];

  /**
   * Runs inside each StepCommand::send(), after it is recorded and its row
   * committed: a synchronous handler (await-before-dispatch), a failure
   * (subscriber isolation) or a host's crash point (crash-mid-step).
   *
   * @var null|\Closure(StepCommand): void
   */
  public static ?\Closure $on_send = null;

  private static ?ScenarioRows $rows = null;

  private static ?ITransactionBoundary $boundary = null;

  public static function reset(): void {
    self::$steps = [];
    self::$sent = [];
    self::$on_send = null;
    self::$rows = null;
    self::$boundary = null;
    self::$unboundRows = [];
  }

  /**
   * Commit scenario row $id on the host connection (its own transaction, or
   * the open one), idempotently. A process step or precheck reads it back
   * with has_row(): it stands for state another context published (D3
   * precheck). Without a bound ScenarioRows it is kept in this php process.
   */
  public static function mark_row(string $id, string $value = '1'): void {
    if (self::$rows === null) {
      self::$unboundRows[$id] = true;
      return;
    }
    $rows = self::$rows;
    $write = static function () use ($rows, $id, $value): void {
      if (!$rows->has($id)) {
        $rows->insert($id, $value);
      }
    };
    $boundary = self::$boundary;
    $boundary === null || $boundary->is_active() ? $write() : $boundary->run($write);
  }

  public static function has_row(string $id): bool {
    return self::$rows !== null ? self::$rows->has($id) : isset(self::$unboundRows[$id]);
  }

  /** @return list<string> the widget ids (minted refs) $label was sent with, in order */
  public static function widgets(string $label): array {
    return array_values(array_map(
      static fn (array $s) => $s['widget'],
      array_filter(self::$sent, static fn (array $s) => $s['label'] === $label),
    ));
  }

  /** Commit each step command's effect row on the host connection (see the class doc). */
  public static function bind(?ScenarioRows $rows, ?ITransactionBoundary $boundary = null): void {
    self::$rows = $rows;
    self::$boundary = $boundary;
  }

  public static function step(string $name): void {
    self::$steps[] = $name;
  }

  public static function sent(StepCommand $command, ?string $commandId): void {
    self::$sent[] = ['label' => $command->label, 'commandId' => $commandId, 'widget' => $command->widget_id];

    if (self::$rows !== null && $commandId !== null) {
      $rows = self::$rows;
      $id = self::row_id($command->label, $commandId);
      $write = static function () use ($rows, $id, $command): void {
        if (!$rows->has($id)) {
          $rows->insert($id, $command->label);
        }
      };
      $boundary = self::$boundary;
      $boundary === null || $boundary->is_active() ? $write() : $boundary->run($write);
    }

    if (self::$on_send !== null) {
      (self::$on_send)($command);
    }
  }

  /** The effect row id of one step command. */
  public static function row_id(string $label, string $commandId): string {
    return "cmd:$label:$commandId";
  }

  /** @return list<string> labels of the commands sent, in order */
  public static function labels(): array {
    return array_map(static fn (array $s) => $s['label'], self::$sent);
  }

  /** @return list<?string> the command ids $label was sent under, in order */
  public static function command_ids(string $label): array {
    return array_values(array_map(
      static fn (array $s) => $s['commandId'],
      array_filter(self::$sent, static fn (array $s) => $s['label'] === $label),
    ));
  }

  /** How many times step $name ran. */
  public static function runs(string $name): int {
    return count(array_filter(self::$steps, static fn (string $s) => $s === $name));
  }
}
