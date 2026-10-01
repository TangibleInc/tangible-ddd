<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console\Ops;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;

/**
 * `ddd:ops:dlq:replay <dlq-id>...`: put dead letters back in the relay
 * (register 3.4: keeps the event id, resets the original outbox row or
 * re-inserts it, deletes the DLQ row, one transaction each). Every id is
 * attempted; the command fails if any of them failed.
 */
#[AsCommand(name: 'ddd:ops:dlq:replay', description: 'Replay dead letters by DLQ id (keeps the event id)')]
final class DlqReplayCommand extends Command {

  public function __construct(private readonly IOutboxAdministration $admin) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addArgument('dlq-id', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'DLQ ids (ddd:ops:dlq:list)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $exit = Command::SUCCESS;
    foreach ((array) $input->getArgument('dlq-id') as $raw) {
      $id = (int) $raw;
      try {
        $this->admin->replay($id);
        $output->writeln("Replayed DLQ #$id.");
      } catch (\Throwable $e) {
        $exit = Command::FAILURE;
        $output->writeln(sprintf('<error>DLQ #%s not replayed: %s</error>', $raw, $e->getMessage()));
      }
    }
    return $exit;
  }
}
