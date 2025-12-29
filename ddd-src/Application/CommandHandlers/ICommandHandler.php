<?php

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;

interface ICommandHandler {
  public function handle(ICommand $command): void;
}

