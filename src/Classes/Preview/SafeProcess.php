<?php

namespace Dashed\DashedFiles\Classes\Preview;

use Throwable;
use Symfony\Component\Process\Process;

/**
 * Start een extern programma op een bestand van een gebruiker. Altijd met
 * een argumentenlijst (nooit een shell-regel), een harde time-out, zonder
 * invoer, met een begrensde uitvoer en met een kale omgeving: het
 * kindproces krijgt geen APP_KEY, databasegegevens of opslagsleutels mee.
 * Gooit nooit: null betekent "niet gelukt om te starten of afgebroken".
 */
final class SafeProcess
{
    // Meer uitvoer dan dit is geen antwoord meer maar een aanval of een fout.
    public const MAX_OUTPUT_BYTES = 1024 * 1024;

    // Zonder PATH in de omgeving van PHP zelf.
    public const DEFAULT_PATH = '/usr/local/bin:/usr/bin:/bin';

    /** @var (callable(array<int, string>): void)|null alleen voor tests: ziet elke argumentenlijst */
    public static $spy = null;

    /** Het moment (microtime) waarop al het werk van de lopende ronde klaar moet zijn, of null. */
    private static ?float $deadline = null;

    /**
     * Voert $work uit met één deadline voor alles wat daarbinnen gestart
     * wordt: elke run() krijgt hooguit de tijd die er nog is.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function withDeadline(float $seconds, callable $work): mixed
    {
        $previous = self::$deadline;
        $own = microtime(true) + max(0.0, $seconds);
        self::$deadline = $previous === null ? $own : min($previous, $own);

        try {
            return $work();
        } finally {
            self::$deadline = $previous;
        }
    }

    /** De tijd die er binnen de deadline nog is, of null als er geen loopt. */
    public static function remaining(): ?float
    {
        return self::$deadline === null ? null : self::$deadline - microtime(true);
    }

    public static function expired(): bool
    {
        $remaining = self::remaining();

        return $remaining !== null && $remaining <= 0.0;
    }

    /**
     * De omgeving van het kindproces: alleen het zoekpad, en HOME en TMPDIR
     * naar de tijdelijke map (ffmpeg en Ghostscript zoeken daar hun
     * instellingen en werkbestanden). Al het andere uit de omgeving van PHP
     * wordt uitdrukkelijk weggelaten.
     *
     * @return array<string, string|false>
     */
    public static function environment(): array
    {
        $environment = [];

        // Symfony vult de omgeving aan met die van PHP; false haalt een naam er weer uit.
        foreach (array_keys((array) getenv() + $_ENV + $_SERVER) as $name) {
            if (is_string($name) && $name !== '') {
                $environment[$name] = false;
            }
        }

        $path = getenv('PATH');
        $temporary = sys_get_temp_dir();

        return array_merge($environment, [
            'PATH' => is_string($path) && $path !== '' ? $path : self::DEFAULT_PATH,
            'HOME' => $temporary,
            'TMPDIR' => $temporary,
            'LC_ALL' => 'C',
        ]);
    }

    /**
     * @param  array<int, string>  $command
     * @return array{exit: int, out: string, err: string}|null
     */
    public static function run(array $command, float $timeout): ?array
    {
        if ($command === [] || ! class_exists(Process::class)) {
            return null;
        }

        $remaining = self::remaining();

        if ($remaining !== null) {
            // De tijd is op: niets meer starten.
            if ($remaining < 0.2) {
                return null;
            }

            $timeout = min($timeout, $remaining);
        }

        $command = array_map('strval', array_values($command));

        if (self::$spy !== null) {
            (self::$spy)($command);
        }

        $out = '';
        $err = '';

        try {
            // De werkmap is de tijdelijke map: een programma dat toch een
            // relatief pad opent komt nergens anders uit.
            $process = new Process($command, sys_get_temp_dir(), self::environment(), null, max(0.2, $timeout));
            $process->setIdleTimeout(null);

            $exit = $process->run(function (string $type, string $chunk) use (&$out, &$err, &$process): void {
                if ($type === Process::OUT) {
                    $out .= $chunk;
                } else {
                    $err .= $chunk;
                }

                if (strlen($out) > self::MAX_OUTPUT_BYTES || strlen($err) > self::MAX_OUTPUT_BYTES) {
                    $process->stop(0);
                }
            });

            if (strlen($out) > self::MAX_OUTPUT_BYTES || strlen($err) > self::MAX_OUTPUT_BYTES) {
                return null;
            }

            return ['exit' => (int) $exit, 'out' => $out, 'err' => $err];
        } catch (Throwable) {
            // Time-out of niet te starten: het proces is door Symfony gestopt.
            return null;
        }
    }
}
