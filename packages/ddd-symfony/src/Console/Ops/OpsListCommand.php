<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console\Ops;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;

/**
 * `ddd:ops:list [--layer=<layer>] [--limit=100] [--format=table|json]`: the
 * one failure view across every layer (D9, register 3.10, 5.1): relay DLQ,
 * delivery ledger, wakeups, stranded processes and the Messenger failure
 * transport, attempts against each layer's budget, the last error, and the
 * repairs that apply (`ddd:ops:dlq:*`, `ddd:ops:stranded`,
 * `messenger:failed:*`). Read-only. `--format=json` prints
 * OperatorItem::to_array() rows.
 */
#[AsCommand(name: 'ddd:ops:list', description: 'List failures across every layer (relay, delivery, wakeup, process, transport)')]
final class OpsListCommand extends Command {

  /** Where each repair label is carried out. */
  private const REPAIRS = [
    'retry' => 'ddd:ops:dlq:retry <event id>',
    'replay' => 'ddd:ops:dlq:replay <DLQ id>',
    'discard' => 'ddd:ops:dlq:discard <DLQ id>',
    'rearm' => 'ddd:ops:stranded --rearm=<intent key>',
    'resume_stranded' => 'ddd:ops:stranded --resume=<process id>',
    'fail_stranded' => 'ddd:ops:stranded --fail=<process id>',
  ];

  public function __construct(private readonly IOperatorView $view) {
    parent::__construct();
  }

  protected function configure(): void {
    $layers = implode('|', array_map(static fn (Layer $l) => $l->value, Layer::cases()));
    $this
      ->addOption('layer', null, InputOption::VALUE_REQUIRED, "Only this layer ($layers)")
      ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows to list', '100')
      ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $layerName = $input->getOption('layer');
    $layer = $layerName === null ? null : Layer::tryFrom((string) $layerName);
    if ($layerName !== null && $layer === null) {
      $output->writeln(sprintf('<error>Unknown layer "%s"; one of %s.</error>', $layerName,
        implode(', ', array_map(static fn (Layer $l) => $l->value, Layer::cases()))));
      return Command::INVALID;
    }
    $format = (string) $input->getOption('format');
    if (!in_array($format, ['table', 'json'], true)) {
      $output->writeln('<error>--format is table or json.</error>');
      return Command::INVALID;
    }

    $items = $this->view->list($layer, max(1, (int) $input->getOption('limit')));

    if ($format === 'json') {
      $output->writeln(json_encode(
        array_map(static fn (OperatorItem $i) => $i->to_array(), $items),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
      ), OutputInterface::OUTPUT_RAW);
      return Command::SUCCESS;
    }

    if ($items === []) {
      $output->writeln($layer === null ? 'No failures in any layer.' : "No failures in layer {$layer->value}.");
      return Command::SUCCESS;
    }

    $table = new Table($output);
    $table->setHeaders(['layer', 'key', 'attempts', 'first seen (UTC)', 'last error', 'repairs']);
    $used = [];
    foreach ($items as $i) {
      $table->addRow([
        $i->layer->value,
        $i->key,
        $i->budget === null ? (string) $i->attempts : "{$i->attempts}/{$i->budget}",
        $i->first_seen?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? '-',
        mb_strimwidth((string) $i->last_error, 0, 100, '...'),
        implode(', ', $i->repairs),
      ]);
      foreach ($i->repairs as $action) {
        $used[$action] = true;
      }
    }
    $table->render();

    $counts = [];
    foreach ($items as $i) {
      $counts[$i->layer->value] = ($counts[$i->layer->value] ?? 0) + 1;
    }
    $output->writeln(implode(', ', array_map(static fn ($l, $n) => "$l: $n", array_keys($counts), $counts)));
    foreach (array_keys($used) as $action) {
      if (isset(self::REPAIRS[$action])) {
        $output->writeln("  $action: " . self::REPAIRS[$action]);
      }
    }
    return Command::SUCCESS;
  }
}
