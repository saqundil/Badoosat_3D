<?php

declare(strict_types=1);

namespace App\Print;

use App\Support\Config;

/**
 * For a VPS: drives ImageMagick through proc_open.
 *
 * Nothing in a required code path reaches here — the factory only picks it when
 * proc_open exists and the binary answers.
 */
final class ShellDriver extends AbstractPrintDriver
{
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        if (!function_exists('proc_open') || $this->disabled('proc_open')) {
            return $this->available = false;
        }

        try {
            $this->run([$this->binary(), '-version'], 10);
        } catch (PrintDriverException) {
            return $this->available = false;
        }

        return $this->available = true;
    }

    public function convertsColour(): bool
    {
        return true;
    }

    public function toCmyk(string $src, string $dst, string $iccPath): void
    {
        if (!$this->isAvailable()) {
            throw new PrintDriverException('ImageMagick is not reachable from PHP.');
        }

        if (!is_file($src)) {
            throw new PrintDriverException('Artwork missing: ' . $src);
        }

        $command = [
            $this->binary(), $src,
            '-density', '300',
            '-units', 'PixelsPerInch',
            '-background', 'white', '-flatten',
        ];

        if ($iccPath !== '' && is_file($iccPath)) {
            $command[] = '-profile';
            $command[] = $iccPath;
        }

        array_push($command, '-colorspace', 'CMYK', '-quality', '92', $dst);

        $this->run($command, (int) Config::get('print.timeout_seconds', 60));

        if (!is_file($dst)) {
            throw new PrintDriverException('ImageMagick produced no output.');
        }
    }

    private function binary(): string
    {
        return (string) Config::get('print.binaries.magick', 'magick');
    }

    private function disabled(string $function): bool
    {
        $list = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $list, true);
    }

    /** @param string[] $command */
    private function run(array $command, int $timeout): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new PrintDriverException('Could not start ' . $command[0] . '.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + $timeout;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status  = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new PrintDriverException($command[0] . ' timed out after ' . $timeout . 's.');
            }

            usleep(20000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new PrintDriverException(trim($stderr) !== '' ? trim($stderr) : 'Exit code ' . $code);
        }

        return $stdout;
    }
}
