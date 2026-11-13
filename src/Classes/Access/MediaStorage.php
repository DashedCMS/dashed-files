<?php

namespace Dashed\DashedFiles\Classes\Access;

use Throwable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

/**
 * De twee schijven van de mediabibliotheek. Publieke bestanden staan op
 * `dashed`; bestanden van een privé-map staan op een tweede schijf, standaard
 * `dashed-private`. Een tweede schijf en geen zichtbaarheid per bestand:
 * Spatie schrijft elk later formaat met de standaard van de schijf, en op
 * een publieke schijf zou een opnieuw gegenereerd formaat dus weer open staan.
 */
final class MediaStorage
{
    public const PUBLIC_DISK = 'dashed';

    public const DEFAULT_PRIVATE_DISK = 'dashed-private';

    public const PRIVATE_PREFIX = 'private';

    public static function publicDisk(): string
    {
        return self::PUBLIC_DISK;
    }

    public static function privateDisk(): string
    {
        $name = config('dashed-files.private.disk');

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_PRIVATE_DISK;
    }

    public static function diskFor(bool $private): string
    {
        return $private ? self::privateDisk() : self::publicDisk();
    }

    public static function isPrivateDisk(?string $disk): bool
    {
        return is_string($disk) && $disk === self::privateDisk();
    }

    /**
     * Zet de privé-schijf in de configuratie als de applicatie hem niet zelf
     * definieert. Dezelfde driver en gegevens als `dashed`, zichtbaarheid
     * privé en een eigen voorvoegsel; voor een lokale schijf een map buiten
     * alles wat de webserver uitlevert.
     */
    public static function register(): void
    {
        $name = self::privateDisk();

        if (is_array(config("filesystems.disks.{$name}"))) {
            return;
        }

        $public = config('filesystems.disks.' . self::PUBLIC_DISK);

        if (! is_array($public)) {
            return;
        }

        config(["filesystems.disks.{$name}" => self::deriveFrom($public)]);
    }

    /**
     * @param  array<string, mixed>  $public
     * @return array<string, mixed>
     */
    public static function deriveFrom(array $public): array
    {
        $private = $public;
        $private['visibility'] = 'private';
        // Een adres hoort er niet bij: een privé-bestand wordt alleen via de ondertekende route geopend.
        unset($private['url'], $private['serve']);

        if (($public['driver'] ?? null) === 'local') {
            $private['root'] = storage_path('app/private-media');
            $private['directory_visibility'] = 'private';

            return $private;
        }

        $private['root'] = trim(trim((string) ($public['root'] ?? ''), '/') . '/' . self::PRIVATE_PREFIX, '/');

        return $private;
    }

    /**
     * Wat de doctor toont: de schijf, of hij schrijfbaar is en (lokaal) of
     * de map buiten de publieke map ligt.
     *
     * @return array{disk: string, configured: bool, driver: ?string, visibility: ?string, writable: bool, error: ?string, local_root: ?string, outside_public: ?bool, same_as_public: bool}
     */
    public static function inspect(bool $probe = true): array
    {
        $name = self::privateDisk();
        $config = config("filesystems.disks.{$name}");
        $configured = is_array($config);
        $driver = $configured ? (string) ($config['driver'] ?? '') : null;
        $root = $configured && $driver === 'local' ? (string) ($config['root'] ?? '') : null;
        $writable = false;
        $error = null;

        // Een proefbestand schrijven, lezen en weer weghalen. Zonder $probe blijft de schijf onaangeroerd.
        if ($configured && $probe) {
            $file = '.dashed-probe-' . Str::lower(Str::random(12));

            try {
                $disk = Storage::disk($name);
                $writable = (bool) $disk->put($file, 'ok') && $disk->get($file) === 'ok';
                $disk->delete($file);
            } catch (Throwable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 200);
            }
        }

        $publicConfig = (array) config('filesystems.disks.' . self::PUBLIC_DISK, []);
        $same = $configured && ($name === self::PUBLIC_DISK || (
            ($config['driver'] ?? null) === ($publicConfig['driver'] ?? null)
            && ($config['bucket'] ?? null) === ($publicConfig['bucket'] ?? null)
            && trim((string) ($config['root'] ?? ''), '/') === trim((string) ($publicConfig['root'] ?? ''), '/')
        ));

        return [
            'disk' => $name,
            'configured' => $configured,
            'driver' => $driver,
            'visibility' => $configured ? (string) ($config['visibility'] ?? 'private') : null,
            'writable' => $writable,
            'error' => $error,
            'local_root' => $root,
            'outside_public' => $root === null ? null : self::isOutsidePublic($root),
            'same_as_public' => $same,
        ];
    }

    /**
     * Vraagt een proefbestand op de privé-schijf anoniem op. Alleen voor de
     * doctor (op commando), nooit bij het opstarten. Het proefbestand heeft
     * een willekeurige naam en gaat altijd weer weg.
     *
     * @return array{status: 'open'|'closed'|'skipped', detail: string}
     */
    public static function probeAnonymous(): array
    {
        $name = self::privateDisk();

        if (! is_array(config("filesystems.disks.{$name}"))) {
            return ['status' => 'skipped', 'detail' => 'the disk is not configured'];
        }

        $file = '.dashed-probe-' . Str::lower(Str::random(40)) . '.txt';
        $disk = null;

        try {
            $disk = Storage::disk($name);
            $disk->put($file, 'dashed private disk probe');

            try {
                $url = (string) $disk->url($file);
            } catch (Throwable) {
                return ['status' => 'skipped', 'detail' => 'the disk cannot make an address, so nothing is reachable by address'];
            }

            if (! preg_match('#^https?://#i', $url)) {
                return ['status' => 'skipped', 'detail' => 'the disk has no absolute address'];
            }

            try {
                $response = \Illuminate\Support\Facades\Http::timeout(2)->connectTimeout(2)->withoutRedirecting()->get($url);
            } catch (Throwable $exception) {
                return ['status' => 'skipped', 'detail' => 'the request failed: ' . mb_substr($exception->getMessage(), 0, 120)];
            }

            return $response->status() === 200
                ? ['status' => 'open', 'detail' => 'status 200']
                : ['status' => 'closed', 'detail' => 'status ' . $response->status()];
        } catch (Throwable $exception) {
            return ['status' => 'skipped', 'detail' => 'could not write the test file: ' . mb_substr($exception->getMessage(), 0, 120)];
        } finally {
            if ($disk) {
                rescue(fn () => $disk->delete($file), report: false);
            }
        }
    }

    /**
     * Of deze lokale map buiten alles ligt wat de webserver uitlevert: de
     * map `public`, `storage/app/public` en de map van de publieke schijf.
     */
    public static function isOutsidePublic(string $root): bool
    {
        $root = self::canonical($root);
        $publicConfig = (array) config('filesystems.disks.' . self::PUBLIC_DISK, []);

        $served = array_filter([
            self::canonical(public_path()),
            self::canonical(storage_path('app/public')),
            ($publicConfig['driver'] ?? null) === 'local' && filled($publicConfig['root'] ?? null) ? self::canonical((string) $publicConfig['root']) : null,
        ]);

        foreach ($served as $path) {
            if ($root === $path || str_starts_with($root . '/', $path . '/')) {
                return false;
            }
        }

        return true;
    }

    private static function canonical(string $path): string
    {
        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, '/');
    }
}
