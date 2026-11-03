<?php

namespace App\Modules\Core\Contracts;

/**
 * Optional marker for a WorkflowModule whose decide() does more than call
 * the engine: it checks who the row names, grants an extension, writes a
 * record, sends a letter.
 *
 * Core's bulk decide (QueueController::decideBulk) goes straight to
 * WorkflowEngine::decide(), so none of that would run for a ticked row.
 * Implement this and the bulk route refuses your module outright, and the
 * shared queue (core::partials.queue) stops offering the checkboxes. Every
 * decision then goes through your own decide route.
 *
 * No methods. Implementing it is the whole declaration.
 */
interface DecidesOneAtATime
{
}
