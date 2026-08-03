<?php

namespace App\Services;

use RuntimeException;

/** Safely sets KEY=value lines in the app's .env file. */
class EnvWriter
{
    public function set(array $values): void
    {
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_writable($path)) {
            throw new RuntimeException(".env not writable at {$path}");
        }

        $contents = file_get_contents($path);
        foreach ($values as $key => $value) {
            $line = $key.'='.$this->quote((string) $value);
            if (preg_match("/^{$key}=.*$/m", $contents)) {
                $contents = preg_replace("/^{$key}=.*$/m", $line, $contents);
            } else {
                $contents = rtrim($contents, "\n")."\n\n{$line}\n";
            }
        }
        file_put_contents($path, $contents);
    }

    protected function quote(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"'.addcslashes($value, '"\\').'"';
        }

        return $value;
    }
}
