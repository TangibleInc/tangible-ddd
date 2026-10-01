<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Symfony\Persistence\PostgresSchema;

/**
 * `ddd:schema:dump [--prefix=] [--since=NNN]`: prints the plain Postgres DDL
 * (X9) with the configured table prefix, for the host's own migrations
 * (Doctrine Migrations `addSql()`, a SQL migration file, ...). Every
 * statement is idempotent. Files come in number order, each headed by a
 * comment naming it; the schema is append-only (L5), so `--since=NNN` (the
 * last file the host already applied) prints exactly the next migration. The
 * Messenger table is created separately by `messenger:setup-transports`.
 *
 * Several consumers (wave 5): each has its own tables and so its own schema
 * history; `--consumer=<name>` prints that consumer's DDL (its table prefix,
 * and `CREATE SCHEMA IF NOT EXISTS` first when it lives in a Postgres
 * schema), and `--since` is that consumer's last applied file. Without
 * `--consumer` the primary consumer's tables are printed, as before.
 */
#[AsCommand(name: 'ddd:schema:dump', description: 'Print the tangible/ddd Postgres schema in file order (--since=NNN: only the files after NNN)')]
final class SchemaDumpCommand extends Command {

  /** @param array<string, string> $consumers several consumers (wave 5): name → its `[schema.]prefix` tables */
  public function __construct(
    private readonly string $tablePrefix = '',
    private readonly array $consumers = [],
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Table prefix, `[schema.]prefix` (default: tangible_ddd.table_prefix)');
    $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only the files numbered after this one (e.g. 008)');
    $this->addOption('consumer', null, InputOption::VALUE_REQUIRED, 'The schema of this consumer (its tables and Postgres schema; each consumer has its own history)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $consumer = $input->getOption('consumer');
    if ($consumer !== null && !isset($this->consumers[$consumer])) {
      $output->writeln(sprintf('<error>Unknown consumer "%s"; one of %s</error>', $consumer, implode(', ', array_keys($this->consumers)) ?: '(none configured)'));
      return Command::FAILURE;
    }
    $prefix = $input->getOption('prefix') ?? ($consumer === null ? $this->tablePrefix : $this->consumers[$consumer]);
    $since = $input->getOption('since');
    if ($since !== null && !preg_match('/^\d{1,3}$/', (string) $since)) {
      $output->writeln('<error>--since takes a schema file number, e.g. 008</error>');
      return Command::INVALID;
    }
    $output->write(PostgresSchema::render((string) $prefix, $since === null ? null : (int) $since), false, OutputInterface::OUTPUT_RAW);
    return Command::SUCCESS;
  }
}
