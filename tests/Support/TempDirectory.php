<?php

namespace LEClient\Tests\Support;

class TempDirectory
{
    private string $path;

    public function __construct(?string $prefix = 'leclient-test-')
    {
        $this->path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        if (!mkdir($this->path, 0755, true) && !is_dir($this->path)) {
            throw new \RuntimeException('Failed to create temp directory: ' . $this->path);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function file(string $name): string
    {
        return $this->path . '/' . ltrim($name, '/');
    }

    public function cleanup(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->path);
    }
}
