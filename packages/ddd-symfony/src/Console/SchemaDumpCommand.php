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
 * `ddd:schema:dump`: prints the plain Postgres DDL (X9) with the configured
 * table prefix, for the host's own migrations (Doctrine Migrations
 * `addSql()`, a SQL migration file, ...). Every statement is idempotent. The
 * Messenger table is created separately by `messenger:setup-transports`.
 */
#[AsCommand(name: 'ddd:schema:dump', description: 'Print the tangible/ddd Postgres schema (outbox, dlq, relay pauses, delivery ledger)')]
final class SchemaDumpCommand extends Command {

  public function __construct(private readonly string $tablePrefix = '') {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Table prefix (default: tangible_ddd.table_prefix)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $prefix = $input->getOption('prefix') ?? $this->tablePrefix;
    $output->write(PostgresSchema::render((string) $prefix), false, OutputInterface::OUTPUT_RAW);
    return Command::SUCCESS;
  }
}
