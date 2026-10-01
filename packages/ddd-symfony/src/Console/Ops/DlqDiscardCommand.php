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
 * `ddd:ops:dlq:discard <dlq-id>...`: give up on dead letters for good
 * (IOutboxAdministration::discard, the `discard` repair of the operator
 * view's relay layer). Every id is attempted; the command fails if any of
 * them failed.
 */
#[AsCommand(name: 'ddd:ops:dlq:discard', description: 'Discard dead letters by DLQ id')]
final class DlqDiscardCommand extends Command {

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
        $this->admin->discard($id);
        $output->writeln("Discarded DLQ #$id.");
      } catch (\Throwable $e) {
        $exit = Command::FAILURE;
        $output->writeln(sprintf('<error>DLQ #%s not discarded: %s</error>', $raw, $e->getMessage()));
      }
    }
    return $exit;
  }
}
