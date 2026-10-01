<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console\Ops;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;

/**
 * `ddd:ops:resume [<selector>] [--holder=ops]`: release a holder's relay
 * pause on one selector, or every pause of the holder when no selector is
 * given. Other holders' pauses stay.
 */
#[AsCommand(name: 'ddd:ops:resume', description: 'Release a relay pause')]
final class ResumeCommand extends Command {

  public function __construct(private readonly IRelayPauseStore $pauses) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addArgument('selector', InputArgument::OPTIONAL, 'The selector to release (default: every selector of the holder)')
      ->addOption('holder', null, InputOption::VALUE_REQUIRED, 'Whose pause to release', 'ops');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $selector = $input->getArgument('selector');
    $holder = (string) $input->getOption('holder');

    $this->pauses->release($holder, $selector === null ? null : (string) $selector);
    $output->writeln($selector === null
      ? "Released every relay pause held by $holder."
      : "Released $holder's relay pause on $selector.");
    return Command::SUCCESS;
  }
}
