<?php

namespace Tests\Feature\Nureen\Concerns;

/**
 * Runs queued jobs synchronously, as phpunit.xml intends.
 *
 * Inside the app container, docker-compose sets QUEUE_CONNECTION=redis, and
 * phpunit.xml's <env name="QUEUE_CONNECTION" value="sync"/> has no
 * force="true", so the container's value wins. Without this, every queued
 * notification a test triggers (and does not fake) lands on the REAL Redis
 * queue, and the dev worker then writes a failed_jobs row into the dev MySQL
 * database for it: 37 rows per run of tests/Feature/Nureen, measured on
 * 1 November 2026. Mail is unaffected (MAIL_MAILER=array still applies), so
 * running them synchronously sends nothing anywhere.
 *
 * Laravel calls setUp<TraitName>() automatically. The proper fix is
 * force="true" in phpunit.xml -- see SHARED_CHANGES.md §7c.
 */
trait KeepsJobsOffTheDevQueue
{
    protected function setUpKeepsJobsOffTheDevQueue(): void
    {
        config(['queue.default' => 'sync']);
    }
}
