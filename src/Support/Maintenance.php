<?php
declare(strict_types=1);

namespace FloCMS\CLI\Support;

use FloCMS\CLI\Project;

/**
 * Maintenance mode for `php flo down` / `php flo up`.
 *
 * The flag is storage/framework/down.json:
 *
 *     {"time": 1767225600, "message": "Back soon", "retry": 600, "allow": ["203.0.113.7", "10.0.0.0/8"]}
 *
 * FloCMS 1.7's public/index.php and public/api.php answer 503 while it
 * exists (except for the allowed IPs). The commands also set the
 * offline_mode setting in the database when it is reachable, so older
 * front controllers and the admin panel see the same state.
 */
final class Maintenance
{
    public const FILE = 'storage/framework/down.json';

    public function __construct(private readonly Project $project)
    {
    }

    public function file(): string
    {
        return $this->project->path(self::FILE);
    }

    public function isDown(): bool
    {
        return is_file($this->file());
    }

    /** @return array{time: int, message: string, retry: int|null, allow: list<string>}|null */
    public function read(): ?array
    {
        if (!$this->isDown()) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->file()), true);
        $data = is_array($data) ? $data : [];

        return [
            'time' => (int) ($data['time'] ?? filemtime($this->file())),
            'message' => (string) ($data['message'] ?? ''),
            'retry' => isset($data['retry']) ? (int) $data['retry'] : null,
            'allow' => array_values(array_filter((array) ($data['allow'] ?? []), 'is_string')),
        ];
    }

    /** @param list<string> $allow */
    public function down(string $message, ?int $retry, array $allow): void
    {
        (new Filesystem($this->project))->write(self::FILE, json_encode([
            'time' => time(),
            'message' => $message,
            'retry' => $retry,
            'allow' => array_values($allow),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    public function up(): bool
    {
        return (new Filesystem($this->project))->delete(self::FILE);
    }

    /** An IPv4/IPv6 address or CIDR range. */
    public static function isValidRange(string $range): bool
    {
        $parts = explode('/', $range, 2);
        $ip = $parts[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($parts[1]) && (int) $parts[1] <= $max;
    }
}
