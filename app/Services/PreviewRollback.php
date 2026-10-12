<?php

namespace App\Services;

use RuntimeException;

/**
 * Internal signal used to unwind a lifecycle preview.
 *
 * `SubscriptionLifecycle::preview()` genuinely executes the action inside a
 * transaction and then throws this to roll it back — which is what guarantees
 * the preview and the commit cannot drift: the preview *is* the commit, undone.
 *
 * Never escapes the service; callers should never catch it.
 */
class PreviewRollback extends RuntimeException {}
