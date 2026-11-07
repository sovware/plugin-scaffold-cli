<?php

declare(strict_types=1);

namespace SyedGalib\PluginScaffoldCli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class Setup
{
    private const SOURCE_FILE = 'wp-plugin-scaffold.php';

    public static function run(string $directory): int
    {
        try {
            $source = $directory . '/' . self::SOURCE_FILE;
            if (!is_file($source) || strpos((string) file_get_contents($source), 'Plugin Name:       WP Plugin Scaffold') === false) {
                throw new RuntimeException('Run this command once from an unpersonalized WP Plugin Scaffold directory.');
            }

            $name = self::ask('Plugin Name');
            $description = self::ask('Plugin Description (optional)');
            $domain = self::ask('Text Domain');
            $namespace = self::ask('PHP Namespace');

            if ($name === '' || $name === 'WP Plugin Scaffold') {
                throw new RuntimeException('Enter a new plugin name.');
            }
            if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $domain) || $domain === 'wp-plugin-scaffold') {
                throw new RuntimeException('Text Domain must be a new lowercase slug, for example my-plugin.');
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) || $namespace === 'WPPluginScaffold') {
                throw new RuntimeException('PHP Namespace must be a new valid namespace, for example Acme\\MyPlugin.');
            }

            $target = $directory . '/' . $domain . '.php';
            if (file_exists($target)) {
                throw new RuntimeException('The target plugin file already exists: ' . $target);
            }

            fwrite(STDOUT, "\nPlugin file: {$domain}.php\nPlugin name: {$name}\nDescription: {$description}\nText domain: {$domain}\nNamespace: {$namespace}\n");
            if (strtolower(self::ask('Apply these changes? (y/N)')) !== 'y') {
                fwrite(STDOUT, "No files changed.\n");
                return 0;
            }

            $changes = self::prepareChanges($directory, $name, $description, $domain, $namespace);
            self::writeChanges($changes, $source, $target);
            foreach (['composer update --lock --no-install --no-scripts --no-interaction', 'composer dump-autoload --no-scripts', 'composer humbug/php-scoper', 'composer add-prefix', 'composer permission'] as $command) {
                fwrite(STDOUT, "\n> {$command}\n");
                passthru($command, $status);
                if ($status !== 0) {
                    throw new RuntimeException("Command failed: {$command}. Fix it, then run composer setup.");
                }
            }

            fwrite(STDOUT, "\nPlugin setup complete.\n");
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            return 1;
        }
    }

    private static function ask(string $label): string
    {
        fwrite(STDOUT, $label . ': ');
        $answer = fgets(STDIN);
        if ($answer === false) {
            throw new RuntimeException('Input ended before setup was complete.');
        }
        return trim($answer);
    }

    private static function prepareChanges(string $directory, string $name, string $description, string $domain, string $namespace): array
    {
        $constants = strtoupper(str_replace('-', '_', $domain));
        $hooks = str_replace('-', '_', $domain);
        $replacements = [
            'WP_PLUGIN_SCAFFOLD' => $constants,
            'wp_plugin_scaffold' => $hooks,
            'WPPluginScaffold' => $namespace,
            'WP Plugin Scaffold' => $name,
            'WP plugin scaffold' => $name,
            'wp-plugin-scaffold' => $domain,
        ];
        $changes = [];
        $paths = [
            $directory . '/wp-plugin-scaffold.php',
            $directory . '/readme.md',
            $directory . '/readme.txt',
        ];
        foreach (['includes', 'assets', 'dev-tools', 'helpers', 'templates', 'languages'] as $folder) {
            if (!is_dir($directory . '/' . $folder)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/' . $folder, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = substr($file->getPathname(), strlen($directory) + 1);
                if (strpos($relative, 'assets/build/') === 0 || !preg_match('/\.(?:php|js|css|scss|json|md|txt|xml)$/', $relative)) {
                    continue;
                }
                $paths[] = $file->getPathname();
            }
        }

        foreach ($paths as $path) {
            if (!is_file($path)) {
                throw new RuntimeException('Required scaffold file is missing: ' . $path);
            }
            $original = (string) file_get_contents($path);
            $lines = explode("\n", $original);
            foreach ($lines as &$line) {
                if (preg_match('/^\s*\*\s*(?:Plugin URI|Author URI):/', $line)) {
                    continue;
                }
                $line = str_replace(array_keys($replacements), array_values($replacements), $line);
                $line = str_replace('\\PluginScaffold\\Core\\Scaffold', '\\' . $namespace . '\\Core\\Scaffold', $line);
                if (basename($path) === self::SOURCE_FILE && preg_match('/^(\s*\* Description:\s*).*/', $line, $match)) {
                    $line = $match[1] . $description;
                }
            }
            unset($line);
            $updated = implode("\n", $lines);
            if ($updated !== $original) {
                $changes[$path] = $updated;
            }
        }

        $composerPath = $directory . '/composer.json';
        $composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
        $composer['name'] = 'syedgalib/' . $domain;
        unset($composer['autoload']['psr-4']['WPPluginScaffold\\']);
        $composer['autoload']['psr-4'][$namespace . '\\'] = '../includes/';
        $changes[$composerPath] = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        $packagePath = $directory . '/package.json';
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);
        $package['name'] = $domain;
        $package['description'] = $description;
        $changes[$packagePath] = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return $changes;
    }

    private static function writeChanges(array $changes, string $source, string $target): void
    {
        $originals = [];
        $written = [];
        try {
            foreach ($changes as $path => $contents) {
                $originals[$path] = (string) file_get_contents($path);
                $temporary = tempnam(dirname($path), '.scaffold-');
                if ($temporary === false || file_put_contents($temporary, $contents) === false) {
                    throw new RuntimeException('Could not prepare ' . $path);
                }
                chmod($temporary, fileperms($path) & 0777);
                if (!rename($temporary, $path)) {
                    throw new RuntimeException('Could not write ' . $path);
                }
                $written[] = $path;
            }
            if (!rename($source, $target)) {
                throw new RuntimeException('Could not rename the plugin entry file.');
            }
        } catch (Throwable $error) {
            foreach ($written as $path) {
                file_put_contents($path, $originals[$path]);
            }
            throw $error;
        }
    }
}
