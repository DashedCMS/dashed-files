<?php

namespace Dashed\DashedFiles\Classes\Queue;

/**
 * Het werkbudget van één slag van een wachtrijtaak die zichzelf doorschuift.
 *
 * Laravels standaard `retry_after` is 90 seconden, en zo staat hij in bijna
 * elk klantproject. Een taak die langer loopt wordt door de wachtrij
 * opnieuw vrijgegeven terwijl hij nog draait: met één poging eindigt dat
 * als MaxAttemptsExceededException in failed_jobs, met meer pogingen draait
 * hij dubbel. Daarom heeft elke taak van de mediabibliotheek een `timeout`
 * van hooguit MAX_JOB_TIMEOUT, en stopt hij na SECONDS met nieuw werk: wat
 * overblijft gaat naar de volgende slag. Het verschil (25 s) is de ruimte
 * voor de laatste handeling die al begonnen was.
 */
final class WorkBudget
{
    /** De hoogste `timeout` die een taak van de mediabibliotheek mag hebben: onder retry_after 90, met marge. */
    public const MAX_JOB_TIMEOUT = 85;

    /** Na zoveel seconden begint een slag niet meer aan nieuw werk. */
    public const SECONDS = 60;

    /**
     * Voor tests: de klok.
     *
     * @var (callable(): float)|null
     */
    public static $clock = null;

    private float $started;

    public function __construct(private readonly float $seconds = self::SECONDS)
    {
        $this->started = self::now();
    }

    public static function start(float $seconds = self::SECONDS): self
    {
        return new self($seconds);
    }

    /** Of het budget op is: dan geen nieuw werk meer in deze slag. */
    public function spent(): bool
    {
        return self::now() - $this->started >= $this->seconds;
    }

    public static function now(): float
    {
        return self::$clock ? (float) (self::$clock)() : microtime(true);
    }
}
