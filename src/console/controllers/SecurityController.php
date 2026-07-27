<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use lindemannrock\searchmanager\SearchManager;
use yii\console\ExitCode;

/**
 * Security utilities for Search Manager
 *
 * @since 5.0.0
 */
class SecurityController extends Controller
{
    /**
     * Generate a secure salt for IP hashing and optionally update .env file
     *
     * @return int
     */
    public function actionGenerateSalt(): int
    {
        $pluginName = SearchManager::$plugin->getSettings()->getFullName();
        $this->stdout("{$pluginName} - IP Hash Salt Generator\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        // Generate cryptographically secure random salt
        $salt = bin2hex(random_bytes(32)); // 64-character hex string

        $this->stdout("Generated secure salt:\n", Console::FG_YELLOW);
        $this->stdout($salt . "\n\n", Console::FG_GREEN);

        // Check if .env file exists and try to update it
        $envPath = $this->getEnvPath();

        if (!$this->fileExists($envPath)) {
            $this->stdout("Warning: .env file not found at: {$envPath}\n\n", Console::FG_RED);
            $this->printManualAssignment($salt);
            return ExitCode::OK;
        }

        // Read current .env file
        $originalContent = $this->readFile($envPath);
        if ($originalContent === false) {
            return $this->failWithManualAssignment($salt);
        }

        $saltExists = $this->matchSaltAssignment($originalContent);
        if ($saltExists === false) {
            return $this->failWithManualAssignment($salt);
        }

        $envContent = $originalContent;

        if ($saltExists === 1) {
            $this->stdout("Existing SEARCH_MANAGER_IP_SALT found in .env\n\n", Console::FG_YELLOW);
            $this->stdout("WARNING: ", Console::FG_RED);
            $this->stdout("Replacing the salt will break unique visitor tracking!\n");
            $this->stdout("All existing analytics will use the old hash values.\n\n");

            if (!$this->confirm('Do you want to replace the existing salt?', false)) {
                $this->stdout("\nOperation cancelled. Existing salt unchanged.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            // Replace existing salt
            $envContent = $this->replaceSaltAssignment($envContent, $salt);
            if ($envContent === null) {
                return $this->failWithManualAssignment($salt);
            }

            $action = "Updated";
        } else {
            // Append new salt
            if (!empty($envContent) && substr($envContent, -1) !== "\n") {
                $envContent .= "\n";
            }
            $envContent .= "\n# {$pluginName} IP Hash Salt (generated " . date('Y-m-d H:i:s') . ")\n";
            $envContent .= 'SEARCH_MANAGER_IP_SALT="' . $salt . '"' . "\n";
            $action = "Added";
        }

        if (!$this->replaceFileAtomically($envPath, $envContent)) {
            return $this->failWithManualAssignment($salt);
        }

        $this->stdout("\n✓ {$action} SEARCH_MANAGER_IP_SALT in .env file\n", Console::FG_GREEN);
        $this->stdout("Location: {$envPath}\n\n", Console::FG_CYAN);

        $this->stdout("Important:\n", Console::FG_YELLOW);
        $this->stdout("• Never commit .env to version control\n");
        $this->stdout("• Store the salt securely (password manager recommended)\n");
        $this->stdout("• Use the SAME salt across all environments (dev/staging/production)\n");
        $this->stdout("• Changing the salt will reset unique visitor tracking\n\n");

        return ExitCode::OK;
    }

    /**
     * Resolve the project environment file path.
     */
    protected function getEnvPath(): string
    {
        return defined('CRAFT_BASE_PATH')
            ? CRAFT_BASE_PATH . DIRECTORY_SEPARATOR . '.env'
            : \Craft::getAlias('@root/.env');
    }

    /**
     * Replace a file through a verified temporary file in the same directory.
     */
    protected function replaceFileAtomically(string $path, string $content): bool
    {
        $directory = dirname($path);
        $temporaryPath = $this->createTemporaryFile($directory, '.' . basename($path) . '.tmp-');
        if ($temporaryPath === false) {
            return false;
        }

        $temporaryHandle = null;

        try {
            if (dirname($temporaryPath) !== $directory) {
                return false;
            }

            $temporaryHandle = $this->openTemporaryFile($temporaryPath);
            if ($temporaryHandle === false) {
                $temporaryHandle = null;
                return false;
            }

            $written = $this->writeTemporaryFile($temporaryHandle, $content);
            if ($written !== strlen($content)) {
                return false;
            }

            if (!$this->flushTemporaryFile($temporaryHandle)) {
                return false;
            }

            $closed = $this->closeTemporaryFile($temporaryHandle);
            $temporaryHandle = null;
            if (!$closed) {
                return false;
            }

            if ($this->readFile($temporaryPath) !== $content) {
                return false;
            }

            $existingMode = $this->getFileMode($path);
            if ($existingMode !== false) {
                $expectedMode = $existingMode & 0777;
                if (!$this->setFileMode($temporaryPath, $expectedMode)) {
                    return false;
                }

                $temporaryMode = $this->getFileMode($temporaryPath);
                if ($temporaryMode === false || ($temporaryMode & 0777) !== $expectedMode) {
                    return false;
                }
            }

            if (!$this->renameFile($temporaryPath, $path)) {
                return false;
            }

            $temporaryPath = null;
            return true;
        } finally {
            if ($temporaryHandle !== null) {
                $this->closeTemporaryFile($temporaryHandle);
            }

            if ($temporaryPath !== null && $this->fileExists($temporaryPath)) {
                $this->deleteFile($temporaryPath);
            }
        }
    }

    /**
     * Print the generated salt as a manual environment assignment.
     */
    protected function printManualAssignment(string $salt): void
    {
        $this->stdout("Manually add this to your .env file:\n", Console::FG_CYAN);
        $this->stdout("SEARCH_MANAGER_IP_SALT=\"{$salt}\"\n\n", Console::FG_GREEN);
    }

    /**
     * Report a safe replacement failure without discarding the generated salt.
     */
    protected function failWithManualAssignment(string $salt): int
    {
        $this->stdout("\nError: Could not safely update .env file\n", Console::FG_RED);
        $this->printManualAssignment($salt);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    protected function matchSaltAssignment(string $content): int|false
    {
        return preg_match('/^SEARCH_MANAGER_IP_SALT=/m', $content);
    }

    protected function replaceSaltAssignment(string $content, string $salt): ?string
    {
        return preg_replace(
            '/^SEARCH_MANAGER_IP_SALT=.*$/m',
            'SEARCH_MANAGER_IP_SALT="' . $salt . '"',
            $content
        );
    }

    protected function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    protected function readFile(string $path): string|false
    {
        return @file_get_contents($path);
    }

    protected function createTemporaryFile(string $directory, string $prefix): string|false
    {
        return @tempnam($directory, $prefix);
    }

    /**
     * @return resource|false
     */
    protected function openTemporaryFile(string $path): mixed
    {
        return @fopen($path, 'wb');
    }

    /**
     * @param resource $handle
     */
    protected function writeTemporaryFile(mixed $handle, string $content): int|false
    {
        return @fwrite($handle, $content);
    }

    /**
     * @param resource $handle
     */
    protected function flushTemporaryFile(mixed $handle): bool
    {
        return @fflush($handle);
    }

    /**
     * @param resource $handle
     */
    protected function closeTemporaryFile(mixed $handle): bool
    {
        return @fclose($handle);
    }

    protected function getFileMode(string $path): int|false
    {
        return @fileperms($path);
    }

    protected function setFileMode(string $path, int $mode): bool
    {
        return @chmod($path, $mode);
    }

    protected function renameFile(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    protected function deleteFile(string $path): bool
    {
        return @unlink($path);
    }
}
