<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console\Ops;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;

/**
 * `ddd:ops:dlq:list [--limit=50] [--after=<dlq id>]`: the relay dead-letter
 * queue (layer `relay`, 5.1), oldest first, one page at a time.
 */
#[AsCommand(name: 'ddd:ops:dlq:list', description: 'List relay dead letters (oldest first)')]
final class DlqListCommand extends Command {

  public function __construct(private readonly IOutboxAdministration $admin) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per page', '50')
      ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Show dead letters after this DLQ id');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $limit = max(1, (int) $input->getOption('limit'));
    $after = $input->getOption('after');
    $letters = $this->admin->dead_letters($limit, $after === null ? null : (string) $after);

    if ($letters === []) {
      $output->writeln('No dead letters.');
      return Command::SUCCESS;
    }

    $table = new Table($output);
    $table->setHeaders(['DLQ id', 'event id', 'event type', 'attempts', 'dead-lettered at (UTC)', 'error']);
    foreach ($letters as $d) {
      $table->addRow([
        $d->dlq_id, $d->event_id, $d->record->event_type, $d->attempts,
        $d->dead_lettered_at->format('Y-m-d H:i:s'), mb_strimwidth($d->error, 0, 120, '...'),
      ]);
    }
    $table->render();

    if (count($letters) === $limit) {
      $output->writeln(sprintf('More may follow: ddd:ops:dlq:list --after=%d', end($letters)->dlq_id));
    }
    $output->writeln('Replay (keeps the event id): ddd:ops:dlq:replay <DLQ id>; retry the outbox row: ddd:ops:dlq:retry <event id>');
    return Command::SUCCESS;
  }
}
