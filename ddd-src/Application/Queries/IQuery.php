<?php

namespace TangibleDDD\Application\Queries;

interface IQuery {
  public function send(): mixed;
}

