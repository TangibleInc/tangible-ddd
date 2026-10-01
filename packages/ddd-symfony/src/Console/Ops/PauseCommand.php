<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console\Ops;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;

/**
 * `ddd:ops:pause <selector> [--holder=ops] [--for=<seconds>]`: hold relay
 * submission for matching event types (register 3.4, C25). A selector is an
 * exact event type, `*`, or a glob such as `widget_*`. Holds are per holder,
 * so a deploy's pause and an operator's pause release independently.
 */
#[AsCommand(name: 'ddd:ops:pause', description: 'Pause relaying of matching event types')]
final class PauseCommand extends Command {

  public function __construct(private readonly IRelayPauseStore $pauses, private readonly IClock $clock) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addArgument('selector', InputArgument::REQUIRED, 'Event type, * or a glob (widget_*)')
      ->addOption('holder', null, InputOption::VALUE_REQUIRED, 'Who holds the pause', 'ops')
      ->addOption('for', null, InputOption::VALUE_REQUIRED, 'Release automatically after this many seconds');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $selector = (string) $input->getArgument('selector');
    $holder = (string) $input->getOption('holder');
    $for = $input->getOption('for');
    $until = $for === null ? null : $this->clock->now()->modify('+' . max(1, (int) $for) . ' seconds');

    $this->pauses->hold($holder, $selector, $until);
    $output->writeln(sprintf(
      'Relay paused for %s (holder %s)%s. Resume with ddd:ops:resume %s --holder=%s',
      $selector, $holder, $until === null ? '' : ' until ' . $until->format('Y-m-d H:i:s') . ' UTC', $selector, $holder
    ));
    return Command::SUCCESS;
  }
}
