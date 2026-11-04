<?php

namespace Tests\Feature\Hani\Concerns;

/**
 * Runs queued jobs synchronously, as phpunit.xml intends.
 *
 * Inside the app container docker-compose sets QUEUE_CONNECTION=redis, and
 * phpunit.xml's <env name="QUEUE_CONNECTION" value="sync"/> has no
 * force="true", so the container's value wins. Without this, every queued
 * notification a Hani test triggers (and does not fake) lands on the REAL
 * Redis queue; the dev worker then fails it -- the job names a model on the
 * test's in-memory SQLite connection, which the worker resolves as a file
 * called "uresearch" -- and writes a failed_jobs row into the dev database.
 * Mail is unaffected (MAIL_MAILER=array), so sync sends nothing anywhere.
 *
 * The same guard as Nureen's trait of the same name, kept in this folder
 * because one member's tests do not import another's. The proper fix for
 * everyone is force="true" in phpunit.xml -- see Hani's SHARED_CHANGES.md,
 * section 7. Laravel calls setUp<TraitName>() automatically.
 */
trait KeepsJobsOffTheDevQueue
{
    protected function setUpKeepsJobsOffTheDevQueue(): void
    {
        config(['queue.default' => 'sync']);
    }
}
