<?php

declare(strict_types=1);

namespace App\Content;

/** Offline character voice. Narration never needs tenant data or API credentials. */
final class MascotNarrator
{
    public static function isAvailable(): bool
    {
        if (filter_var($_ENV['CONTENT_VIDEO_VOICE'] ?? 'true', FILTER_VALIDATE_BOOLEAN) === false) {
            return false;
        }
        exec(escapeshellarg(self::binary()) . ' --version 2>&1', $output, $code);
        return $code === 0;
    }

    public static function binary(): string
    {
        return trim((string) ($_ENV['ESPEAK_BIN'] ?? '')) ?: 'espeak-ng';
    }

    /** @return array{file:string, duration:float}|null */
    public static function speak(string $text, string $directory, int $index): ?array
    {
        $text = preg_replace('/[\x00-\x1f]/u', ' ', $text) ?? '';
        $text = mb_substr(trim($text), 0, 180);
        if ($text === '') {
            return null;
        }
        $source = $directory . '/voice_' . $index . '.txt';
        $file = $directory . '/voice_' . $index . '.wav';
        file_put_contents($source, $text);
        $args = ['-b', '1', '-v', 'en-us', '-s', '175', '-p', '52', '-w', $file, '-f', $source];
        exec(escapeshellarg(self::binary()) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $output, $code);
        if ($code !== 0 || !is_file($file) || filesize($file) < 1000) {
            error_log('[mascot voice] voice unavailable; retaining the animated tour and captions');
            return null;
        }
        // eSpeak writes mono signed 16-bit PCM WAV. Read RIFF chunks rather
        // than assuming every synthesizer uses a 44-byte header.
        $stream = fopen($file, 'rb');
        $rate = 0;
        $dataSize = 0;
        if ($stream !== false) {
            fseek($stream, 12);
            while (!feof($stream) && ($header = fread($stream, 8)) !== false && strlen($header) === 8) {
                $chunk = unpack('a4id/Vsize', $header);
                if ($chunk['id'] === 'fmt ') {
                    $fmt = fread($stream, $chunk['size']);
                    $rate = strlen($fmt) >= 12 ? unpack('Vrate', substr($fmt, 8, 4))['rate'] : 0;
                } elseif ($chunk['id'] === 'data') {
                    $dataSize = $chunk['size'];
                    break;
                } else {
                    fseek($stream, $chunk['size'], SEEK_CUR);
                }
                if ($chunk['size'] % 2) {
                    fseek($stream, 1, SEEK_CUR);
                }
            }
            fclose($stream);
        }
        return $rate > 0 ? ['file' => $file, 'duration' => $dataSize / $rate] : null;
    }
}
