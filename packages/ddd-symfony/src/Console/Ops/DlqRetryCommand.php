<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console\Ops;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;

/**
 * `ddd:ops:dlq:retry <event-id>... [--force]`: reset outbox rows for
 * another relay attempt (register 3.4, O5). Only `pending` and `dlq` rows
 * unless --force; a leased row is always refused. Retrying a dead-lettered
 * row removes its DLQ entry (sfc-5).
 */
#[AsCommand(name: 'ddd:ops:dlq:retry', description: 'Retry outbox rows by event id (dead-lettered or pending; --force for others)')]
final class DlqRetryCommand extends Command {

  public function __construct(private readonly IOutboxAdministration $admin) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addArgument('event-id', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Outbox event ids')
      ->addOption('force', null, InputOption::VALUE_NONE, 'Also retry accepted/cancelled rows (never a leased one)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $force = (bool) $input->getOption('force');
    $exit = Command::SUCCESS;
    foreach ((array) $input->getArgument('event-id') as $eventId) {
      try {
        $this->admin->retry((string) $eventId, $force);
        $output->writeln("Queued $eventId for another relay attempt.");
      } catch (\Throwable $e) {
        $exit = Command::FAILURE;
        $output->writeln(sprintf('<error>%s not retried: %s</error>', $eventId, $e->getMessage()));
      }
    }
    return $exit;
  }
}
