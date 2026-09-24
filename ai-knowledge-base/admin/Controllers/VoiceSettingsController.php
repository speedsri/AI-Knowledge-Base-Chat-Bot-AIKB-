<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;

final class VoiceSettingsController
{
    public function edit(): void
    {
        $pdo = Database::connection();
        $settings = $pdo->query(
            'SELECT * FROM voice_settings WHERE id = 1'
        )->fetch();

        $settings = $settings ?: [
            'id' => 1,
            'is_enabled' => 0,
            'provider' => null,
            'voice_id' => null,
            'settings_json' => null,
            'updated_at' => null,
        ];

        $voiceConfig = json_decode(
            (string) ($settings['settings_json'] ?? '{}'),
            true
        );

        if (!is_array($voiceConfig)) {
            $voiceConfig = [];
        }

        $voiceConfig = array_merge([
            'language' => 'en',
            'stt_provider' => '',
            'stt_model' => '',
            'tts_provider' => '',
            'tts_model' => '',
            'speech_rate' => 1.0,
            'silence_timeout_ms' => 1500,
            'max_recording_seconds' => 60,
            'auto_language_detection' => true,
            'welcome_message' => '',
            'fallback_message' => '',
        ], $voiceConfig);

        View::render('voice_settings/edit', [
            'title' => 'Voice Settings',
            'settings' => $settings,
            'voiceConfig' => $voiceConfig,
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error'], $_SESSION['_flash_status']);
    }

    public function update(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->fail('Your session expired. Please try again.');
        }

        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $provider = trim((string) ($_POST['provider'] ?? ''));
        $voiceId = trim((string) ($_POST['voice_id'] ?? ''));
        $language = trim((string) ($_POST['language'] ?? 'en'));

        $sttProvider = trim((string) ($_POST['stt_provider'] ?? ''));
        $sttModel = trim((string) ($_POST['stt_model'] ?? ''));
        $ttsProvider = trim((string) ($_POST['tts_provider'] ?? ''));
        $ttsModel = trim((string) ($_POST['tts_model'] ?? ''));

        $speechRate = max(
            0.5,
            min(2.0, (float) ($_POST['speech_rate'] ?? 1.0))
        );

        $silenceTimeout = max(
            500,
            min(10000, (int) ($_POST['silence_timeout_ms'] ?? 1500))
        );

        $maxRecording = max(
            5,
            min(300, (int) ($_POST['max_recording_seconds'] ?? 60))
        );

        $autoLanguageDetection =
            isset($_POST['auto_language_detection']);

        $welcomeMessage =
            trim((string) ($_POST['welcome_message'] ?? ''));

        $fallbackMessage =
            trim((string) ($_POST['fallback_message'] ?? ''));

        if (strlen($provider) > 60) {
            $this->fail('Voice provider must be 60 characters or fewer.');
        }

        if (strlen($voiceId) > 100) {
            $this->fail('Voice ID must be 100 characters or fewer.');
        }

        if (strlen($language) > 20) {
            $this->fail('Language value is too long.');
        }

        foreach ([
            $sttProvider,
            $sttModel,
            $ttsProvider,
            $ttsModel,
        ] as $value) {
            if (strlen($value) > 150) {
                $this->fail(
                    'Provider/model values must be 150 characters or fewer.'
                );
            }
        }

        $config = [
            'language' => $language !== '' ? $language : 'en',
            'stt_provider' => $sttProvider,
            'stt_model' => $sttModel,
            'tts_provider' => $ttsProvider,
            'tts_model' => $ttsModel,
            'speech_rate' => $speechRate,
            'silence_timeout_ms' => $silenceTimeout,
            'max_recording_seconds' => $maxRecording,
            'auto_language_detection' => $autoLanguageDetection,
            'welcome_message' => $welcomeMessage,
            'fallback_message' => $fallbackMessage,
        ];

        $settingsJson = json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($settingsJson === false) {
            $this->fail('Could not encode voice configuration.');
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'UPDATE voice_settings SET
                is_enabled = :enabled,
                provider = :provider,
                voice_id = :voice_id,
                settings_json = :settings_json,
                updated_by = :updated_by,
                updated_at = NOW()
             WHERE id = 1'
        );

        $stmt->execute([
            ':enabled' => $isEnabled,
            ':provider' => $provider !== '' ? $provider : null,
            ':voice_id' => $voiceId !== '' ? $voiceId : null,
            ':settings_json' => $settingsJson,
            ':updated_by' => AuthService::userId(),
        ]);

        Logger::audit(
            'voice_settings.updated',
            AuthService::userId(),
            'voice_settings',
            '1',
            [
                'enabled' => (bool) $isEnabled,
                'provider' => $provider,
                'language' => $config['language'],
                'stt_provider' => $sttProvider,
                'tts_provider' => $ttsProvider,
            ]
        );

        $_SESSION['_flash_status'] =
            'Voice settings saved successfully. '
            . 'Voice runtime will be connected in Phase E2.';

        header('Location: /admin/voice-settings');
        exit;
    }

    private function fail(string $message): void
    {
        $_SESSION['_flash_error'] = $message;
        header('Location: /admin/voice-settings');
        exit;
    }
}
