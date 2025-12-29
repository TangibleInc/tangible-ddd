<?php

namespace TangibleDDD\Application\Commands;

interface ICommand {
  public function send(): mixed;
}

