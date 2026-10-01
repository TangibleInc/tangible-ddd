<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoConfigurationError;
use TangibleDDD\Defaults\Pdo\PdoConnection;

abstract class PdoConnectionCases extends PdoTestCase {

  public function test_it_is_the_host_connection_port(): void {
    self::assertInstanceOf(IHostConnection::class, $this->db);
  }

  public function test_construction_refuses_a_pdo_without_errmode_exception(): void {
    $pdo = self::newPdo(static::emulatePrepares(), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);

    $this->expectException(PdoConfigurationError::class);
    $this->expectExceptionMessageMatches('/ERRMODE_EXCEPTION/');
    new PdoConnection($pdo);
  }

  public function test_a_host_that_later_switches_errmode_off_is_refused_on_the_next_call(): void {
    $pdo = self::newPdo(static::emulatePrepares());
    $db = new PdoConnection($pdo);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);

    $this->expectException(PdoConfigurationError::class);
    $db->fetch_one('SELECT 1 AS one');
  }

  public function test_int_parameters_bind_as_integers_so_limit_placeholders_work(): void {
    foreach (['a', 'b', 'c'] as $name) {
      $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', [$name]);
    }

    $rows = $this->db->fetch_all('SELECT name FROM tp_widgets ORDER BY name LIMIT ?', [2]);
    self::assertSame([['name' => 'a'], ['name' => 'b']], $rows);

    $named = $this->db->fetch_all('SELECT name FROM tp_widgets ORDER BY name LIMIT :n OFFSET :o', ['n' => 1, 'o' => 2]);
    self::assertSame([['name' => 'c']], $named);
  }

  public function test_null_and_bool_parameters_bind_as_null_and_zero_one(): void {
    $this->db->execute('INSERT INTO tp_widgets (name, owner_id) VALUES (?, ?)', ['x', null]);
    $row = $this->db->fetch_one('SELECT owner_id, (? = 1) AS t, (? = 0) AS f FROM tp_widgets WHERE name = ?', [true, false, 'x']);

    self::assertNull($row['owner_id']);
    self::assertSame(1, (int) $row['t']);
    self::assertSame(1, (int) $row['f']);
  }

  public function test_strings_bind_as_strings_even_when_numeric(): void {
    $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', ['007']);

    self::assertNotNull($this->db->fetch_one('SELECT id FROM tp_widgets WHERE name = ?', ['007']));
    self::assertNull($this->db->fetch_one('SELECT id FROM tp_widgets WHERE name = ?', ['7']));
  }

  public function test_execute_returns_affected_rows_and_last_insert_id(): void {
    self::assertSame(1, $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', ['a']));
    $id = $this->db->last_insert_id();
    self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $id);

    $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', ['b']);
    self::assertSame(2, $this->db->execute('UPDATE tp_widgets SET name = CONCAT(name, ?)', ['!']));
    self::assertSame(0, $this->db->execute('DELETE FROM tp_widgets WHERE name = ?', ['nope']));
  }

  public function test_fetch_one_returns_null_when_nothing_matches(): void {
    self::assertNull($this->db->fetch_one('SELECT id FROM tp_widgets WHERE name = ?', ['missing']));
    self::assertSame([], $this->db->fetch_all('SELECT id FROM tp_widgets WHERE name = ?', ['missing']));
  }

  public function test_errors_throw_instead_of_returning_false(): void {
    $this->expectException(\PDOException::class);
    $this->db->fetch_all('SELECT * FROM tp_no_such_table');
  }

  public function test_unsupported_parameter_types_are_refused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->db->fetch_one('SELECT ? AS v', [new \stdClass()]);
  }

  public function test_transactions_begin_commit_and_roll_back(): void {
    self::assertFalse($this->db->in_transaction());
    $this->db->begin();
    self::assertTrue($this->db->in_transaction());
    $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', ['rolled']);
    $this->db->rollback();
    self::assertFalse($this->db->in_transaction());

    $this->db->begin();
    $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', ['kept']);
    $this->db->commit();

    $names = array_column($this->db->fetch_all('SELECT name FROM tp_widgets'), 'name');
    self::assertSame(['kept'], $names);
  }

  public function test_begin_inside_an_open_transaction_throws(): void {
    $this->db->begin();
    try {
      $this->expectException(\PDOException::class);
      $this->db->begin();
    } finally {
      $this->db->rollback();
    }
  }

  public function test_is_duplicate_key_matches_mysql_1062_only(): void {
    $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', ['dup']);

    $duplicate = $this->failure(fn () => $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', ['dup']));
    self::assertTrue($this->db->is_duplicate_key($duplicate));
    self::assertTrue($this->db->is_duplicate_key(new \RuntimeException('wrapped', 0, $duplicate)), 'matched through the previous chain');

    // Same SQLSTATE class 23000, different driver codes: NOT duplicates.
    $notNull = $this->failure(fn () => $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', [null]));
    $foreignKey = $this->failure(fn () => $this->db->execute('INSERT INTO tp_widgets (name, owner_id) VALUES (?, ?)', ['orphan', 999999]));
    self::assertSame('23000', $notNull->errorInfo[0]);
    self::assertSame('23000', $foreignKey->errorInfo[0]);
    self::assertFalse($this->db->is_duplicate_key($notNull));
    self::assertFalse($this->db->is_duplicate_key($foreignKey));

    self::assertFalse($this->db->is_duplicate_key(new \RuntimeException('no driver error')));
    self::assertFalse($this->db->is_duplicate_key(new \PDOException('SQLSTATE[23000]: 1062 lookalike message')));
  }

  private function failure(callable $fn): \PDOException {
    try {
      $fn();
    } catch (\PDOException $e) {
      return $e;
    }
    self::fail('expected a PDOException');
  }
}
