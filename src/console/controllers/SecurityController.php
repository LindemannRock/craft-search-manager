<?php

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
     * @since 5.0.0
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
        $envPath = defined('CRAFT_BASE_PATH') ? CRAFT_BASE_PATH . DIRECTORY_SEPARATOR . '.env' : \Craft::getAlias('@root/.env');

        if (!file_exists($envPath)) {
            $this->stdout("Warning: .env file not found at: {$envPath}\n\n", Console::FG_RED);
            $this->stdout("Manually add this to your .env file:\n", Console::FG_CYAN);
            $this->stdout("SEARCH_MANAGER_IP_SALT=\"{$salt}\"\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        // Read current .env file
        $envContent = file_get_contents($envPath);
        $saltExists = preg_match('/^SEARCH_MANAGER_IP_SALT=/m', $envContent);

        if ($saltExists) {
            $this->stdout("Existing SEARCH_MANAGER_IP_SALT found in .env\n\n", Console::FG_YELLOW);
            $this->stdout("WARNING: ", Console::FG_RED);
            $this->stdout("Replacing the salt will break unique visitor tracking!\n");
            $this->stdout("All existing analytics will use the old hash values.\n\n");

            if (!$this->confirm('Do you want to replace the existing salt?', false)) {
                $this->stdout("\nOperation cancelled. Existing salt unchanged.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            // Replace existing salt
            $envContent = preg_replace(
                '/^SEARCH_MANAGER_IP_SALT=.*$/m',
                'SEARCH_MANAGER_IP_SALT="' . $salt . '"',
                $envContent
            );
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

        // Write back to .env file
        if (file_put_contents($envPath, $envContent) === false) {
            $this->stdout("\nError: Could not write to .env file\n", Console::FG_RED);
            $this->stdout("Please add manually:\n", Console::FG_CYAN);
            $this->stdout("SEARCH_MANAGER_IP_SALT=\"{$salt}\"\n\n", Console::FG_GREEN);
            return ExitCode::UNSPECIFIED_ERROR;
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
}
