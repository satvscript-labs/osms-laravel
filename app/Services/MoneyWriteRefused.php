<?php

namespace App\Services;

use LogicException;

/**
 * E2.1 — a ledger row or a charge was written outside the engine, or someone
 * tried to delete one. A programming error, never an operator error: it means a
 * code path forgot that money is only ever recorded through the engine.
 */
class MoneyWriteRefused extends LogicException
{
}
