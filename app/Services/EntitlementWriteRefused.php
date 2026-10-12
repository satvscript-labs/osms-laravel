<?php

namespace App\Services;

use LogicException;

/**
 * E1.5 — somebody tried to write a subscription outside the billing engine.
 *
 * Only `SubscriptionLifecycle` may create, change or delete a subscription
 * (CLAUDE.md, billing rule 1). This is thrown by `Subscription`'s write guard,
 * so a forgotten writer fails the test suite the day it is written instead of
 * quietly changing what a customer may do.
 *
 * A LogicException, deliberately: it is a programming error, never an operator
 * error, so the lifecycle preview (which shows InvalidArgumentException messages
 * to the operator) reports it rather than presenting it as advice.
 */
class EntitlementWriteRefused extends LogicException {}
