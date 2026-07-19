<?php

namespace TangibleDDD\Application\Correlation;

/**
 * The ontology's node taxonomy — CLOSED AT THREE (spec §12; admissions are
 * rulings, not drift). Acts are audited command passes; Facts are published
 * integration events; Trajectories are LongProcesses. Workflows are
 * trajectory overlays, agents are root annotations, and process-in-process
 * spells through the existing kinds — all three pressure points already
 * ruled against a fourth kind.
 */
enum Kind: string {
  case Act = 'act';
  case Fact = 'fact';
  case Trajectory = 'trajectory';
}
