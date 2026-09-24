<?php

$kbName = is_array($knowledgeBase ?? null)
    ? (string) ($knowledgeBase['name'] ?? '')
    : '';

?>

<div class="container-fluid py-3 py-md-4">
    <div class="chat-shell mx-auto">

        <div class="card shadow border-0 chat-card">

            <div class="card-header bg-white py-3 px-3 px-md-4">
                <div class="d-flex align-items-center gap-3">

                    <div
                        class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                        style="width:48px;height:48px;"
                    >
                        <i class="bi bi-stars fs-4"></i>
                    </div>

                    <div class="flex-grow-1">
                        <h1 class="h5 mb-1">
                            Dynamic Technologies AI Assistant
                        </h1>

                        <div class="small text-secondary">
                            Ask a question about our knowledge base
                        </div>
                    </div>

                    <span class="badge text-bg-success">
                        Online
                    </span>

                </div>
            </div>

            <?php if (!$knowledgeBase): ?>

                <div class="alert alert-warning m-4">
                    No active knowledge base is currently available.
                </div>

            <?php else: ?>

                <div
                    id="chatMessages"
                    class="card-body p-3 p-md-4 flex-grow-1"
                >

                    <div class="d-flex mb-3">
                        <div
                            class="chat-bubble chat-assistant bg-white border rounded-4 shadow-sm p-3"
                        >
                            <div class="small fw-semibold mb-1">
                                AI Assistant
                            </div>

                            <div>
                                Hello. How can I help you today?
                            </div>
                        </div>
                    </div>

                </div>

                <div class="card-footer bg-white p-3">

                    <form
                        id="chatForm"
                        class="d-flex gap-2 align-items-end"
                    >

                        <div class="flex-grow-1">
                            <textarea
                                id="messageInput"
                                class="form-control"
                                rows="1"
                                maxlength="4000"
                                placeholder="Type your message..."
                                autocomplete="off"
                                required
                            ></textarea>

                            <div class="form-text">
                                Knowledge base:
                                <strong>
                                    <?= htmlspecialchars(
                                        $kbName,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>
                            </div>
                        </div>

                        <button
                            id="micButton"
                            class="btn btn-outline-danger"
                            type="button"
                            title="Speak your message"
                            aria-label="Speak your message"
                        >
                            <i class="bi bi-mic-fill"></i>
                        </button>

                        <button
                            id="speakerButton"
                            class="btn btn-outline-secondary"
                            type="button"
                            title="Voice replies enabled"
                            aria-label="Toggle spoken replies"
                        >
                            <i class="bi bi-volume-up-fill"></i>
                        </button>

                        <button
                            id="sendButton"
                            class="btn btn-primary px-4"
                            type="submit"
                        >
                            <i class="bi bi-send"></i>
                            <span class="d-none d-sm-inline ms-1">
                                Send
                            </span>
                        </button>

                    </form>

                    <div
                        id="voiceStatus"
                        class="small text-secondary mt-2"
                    ></div>

                    <div
                        id="chatError"
                        class="small text-danger mt-2 d-none"
                    ></div>

                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php if ($knowledgeBase): ?>

<script>
(() => {
    'use strict';

    const csrfToken =
        <?= json_encode(
            $csrfToken,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        ) ?>;

    const voiceConfig =
        <?= json_encode(
            $voiceConfig ?? [],
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        ) ?>;

    const form =
        document.getElementById('chatForm');

    const input =
        document.getElementById('messageInput');

    const sendButton =
        document.getElementById('sendButton');

    const messages =
        document.getElementById('chatMessages');

    const errorBox =
        document.getElementById('chatError');

    const micButton =
        document.getElementById('micButton');

    const speakerButton =
        document.getElementById('speakerButton');

    const voiceStatus =
        document.getElementById('voiceStatus');

    let busy = false;
    let recognition = null;
    let listening = false;
    let speechEnabled = true;
    let nextInputMode = 'text';

    function scrollToBottom() {
        messages.scrollTop =
            messages.scrollHeight;
    }

    function createBubble(role, text) {
        const row =
            document.createElement('div');

        row.className =
            role === 'user'
                ? 'd-flex mb-3'
                : 'd-flex mb-3';

        const bubble =
            document.createElement('div');

        bubble.className =
            role === 'user'
                ? 'chat-bubble chat-user bg-primary text-white rounded-4 shadow-sm p-3'
                : 'chat-bubble chat-assistant bg-white border rounded-4 shadow-sm p-3';

        const label =
            document.createElement('div');

        label.className =
            'small fw-semibold mb-1';

        label.textContent =
            role === 'user'
                ? 'You'
                : 'AI Assistant';

        const content =
            document.createElement('div');

        content.textContent = text;

        bubble.appendChild(label);
        bubble.appendChild(content);
        row.appendChild(bubble);

        messages.appendChild(row);
        scrollToBottom();

        return bubble;
    }

    function showTyping() {
        const row =
            document.createElement('div');

        row.id = 'typingIndicator';
        row.className = 'd-flex mb-3';

        row.innerHTML = `
            <div class="chat-bubble chat-assistant bg-white border rounded-4 p-3">
                <span class="typing-dot">●</span>
                <span class="typing-dot">●</span>
                <span class="typing-dot">●</span>
            </div>
        `;

        messages.appendChild(row);
        scrollToBottom();
    }

    function hideTyping() {
        document
            .getElementById('typingIndicator')
            ?.remove();
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    function clearError() {
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
    }

    async function sendMessage(text, inputMode = 'text') {
        clearError();

        busy = true;
        sendButton.disabled = true;
        input.disabled = true;

        createBubble('user', text);
        showTyping();

        try {
            const response = await fetch(
                '/chat/message',
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',
                        'Accept':
                            'application/json'
                    },

                    credentials: 'same-origin',

                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        message: text,
                        input_mode: inputMode
                    })
                }
            );

            const data = await response.json();

            hideTyping();

            if (
                !response.ok
                || data.ok !== true
            ) {
                if (
                    data.error
                    === 'too_many_requests'
                ) {
                    throw new Error(
                        'Too many messages. Please wait a few minutes and try again.'
                    );
                }

                throw new Error(
                    'The assistant could not answer right now.'
                );
            }

            const answer =
                data.answer || 'No answer returned.';

            createBubble(
                'assistant',
                answer
            );

            if (
                inputMode === 'voice'
                && speechEnabled
            ) {
                speakAnswer(answer);
            }

        } catch (error) {
            hideTyping();

            showError(
                error instanceof Error
                    ? error.message
                    : 'Something went wrong.'
            );
        } finally {
            busy = false;
            sendButton.disabled = false;
            input.disabled = false;
            input.focus();
        }
    }

    function setVoiceStatus(message, isError = false) {
        if (!voiceStatus) {
            return;
        }

        voiceStatus.textContent = message || '';

        voiceStatus.classList.toggle(
            'text-danger',
            isError
        );

        voiceStatus.classList.toggle(
            'text-secondary',
            !isError
        );
    }

    function stopSpeaking() {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
    }

    function speakAnswer(text) {
        if (
            !speechEnabled
            || !('speechSynthesis' in window)
            || !text
        ) {
            return;
        }

        stopSpeaking();

        const utterance =
            new SpeechSynthesisUtterance(text);

        const configuredLanguage =
            String(
                voiceConfig.language || 'en'
            );

        utterance.lang = configuredLanguage;

        const rate =
            Number(
                voiceConfig.speech_rate || 1
            );

        utterance.rate =
            Math.max(
                0.5,
                Math.min(2, rate)
            );

        const configuredVoice =
            String(
                voiceConfig.voice_id || ''
            ).toLowerCase();

        if (configuredVoice) {
            const voices =
                window.speechSynthesis.getVoices();

            const voice = voices.find(
                item =>
                    String(item.name)
                        .toLowerCase()
                        === configuredVoice
                    || String(item.voiceURI)
                        .toLowerCase()
                        === configuredVoice
            );

            if (voice) {
                utterance.voice = voice;
            }
        }

        utterance.onerror = () => {
            setVoiceStatus(
                'The browser could not play the voice response.',
                true
            );
        };

        window.speechSynthesis.speak(
            utterance
        );
    }

    function updateSpeakerButton() {
        if (!speakerButton) {
            return;
        }

        const icon =
            speakerButton.querySelector('i');

        if (speechEnabled) {
            speakerButton.classList.remove(
                'btn-secondary'
            );

            speakerButton.classList.add(
                'btn-outline-secondary'
            );

            speakerButton.title =
                'Spoken replies enabled';

            if (icon) {
                icon.className =
                    'bi bi-volume-up-fill';
            }
        } else {
            speakerButton.classList.remove(
                'btn-outline-secondary'
            );

            speakerButton.classList.add(
                'btn-secondary'
            );

            speakerButton.title =
                'Spoken replies muted';

            if (icon) {
                icon.className =
                    'bi bi-volume-mute-fill';
            }
        }
    }

    function setListeningState(state) {
        listening = state;

        if (!micButton) {
            return;
        }

        const icon =
            micButton.querySelector('i');

        if (state) {
            micButton.classList.remove(
                'btn-outline-danger'
            );

            micButton.classList.add(
                'btn-danger'
            );

            micButton.title =
                'Listening — click to stop';

            if (icon) {
                icon.className =
                    'bi bi-stop-fill';
            }

            setVoiceStatus(
                'Listening... speak now.'
            );
        } else {
            micButton.classList.remove(
                'btn-danger'
            );

            micButton.classList.add(
                'btn-outline-danger'
            );

            micButton.title =
                'Speak your message';

            if (icon) {
                icon.className =
                    'bi bi-mic-fill';
            }
        }
    }

    function initialiseVoice() {
        if (!micButton || !speakerButton) {
            return;
        }

        const enabled =
            Boolean(voiceConfig.enabled);

        if (!enabled) {
            micButton.disabled = true;
            speakerButton.disabled = true;

            setVoiceStatus(
                'Voice assistant is disabled in Voice Settings.'
            );

            return;
        }

        if (!window.isSecureContext) {
            micButton.disabled = true;

            setVoiceStatus(
                'Microphone access requires HTTPS.',
                true
            );

            return;
        }

        if (!('speechSynthesis' in window)) {
            speakerButton.disabled = true;
        }

        const Recognition =
            window.SpeechRecognition
            || window.webkitSpeechRecognition;

        if (!Recognition) {
            micButton.disabled = true;

            setVoiceStatus(
                'Speech recognition is not supported by this browser. Text chat still works.',
                true
            );

            return;
        }

        recognition = new Recognition();

        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;

        recognition.lang =
            String(
                voiceConfig.language || 'en'
            );

        recognition.onstart = () => {
            setListeningState(true);
        };

        recognition.onresult = event => {
            let interim = '';
            let finalText = '';

            for (
                let i = event.resultIndex;
                i < event.results.length;
                i++
            ) {
                const transcript =
                    event.results[i][0].transcript;

                if (event.results[i].isFinal) {
                    finalText += transcript;
                } else {
                    interim += transcript;
                }
            }

            const shown =
                (finalText || interim).trim();

            if (shown) {
                input.value = shown;
            }

            if (finalText.trim()) {
                nextInputMode = 'voice';
            }
        };

        recognition.onerror = event => {
            setListeningState(false);

            let message =
                'Voice recognition failed. Please try again.';

            if (event.error === 'not-allowed') {
                message =
                    'Microphone permission was denied. Allow microphone access in your browser and try again.';
            } else if (
                event.error === 'no-speech'
            ) {
                message =
                    'No speech was detected. Please try again.';
            } else if (
                event.error === 'audio-capture'
            ) {
                message =
                    'No microphone was available.';
            }

            setVoiceStatus(
                message,
                true
            );
        };

        recognition.onend = () => {
            const shouldSend =
                nextInputMode === 'voice'
                && input.value.trim() !== '';

            setListeningState(false);

            if (
                shouldSend
                && !busy
            ) {
                setVoiceStatus(
                    'Voice captured. Sending...'
                );

                setTimeout(
                    () => form.requestSubmit(),
                    100
                );
            } else if (!busy) {
                setVoiceStatus(
                    'Press the microphone to speak.'
                );
            }
        };

        micButton.addEventListener(
            'click',
            () => {
                clearError();

                if (busy) {
                    return;
                }

                if (listening) {
                    recognition.stop();
                    return;
                }

                stopSpeaking();

                nextInputMode = 'text';
                input.value = '';

                try {
                    recognition.start();
                } catch (error) {
                    setVoiceStatus(
                        'Microphone could not start. Please try again.',
                        true
                    );
                }
            }
        );

        speakerButton.addEventListener(
            'click',
            () => {
                speechEnabled =
                    !speechEnabled;

                if (!speechEnabled) {
                    stopSpeaking();
                }

                updateSpeakerButton();
            }
        );

        updateSpeakerButton();

        setVoiceStatus(
            'Press the microphone to speak.'
        );
    }

    form.addEventListener(
        'submit',
        async (event) => {
            event.preventDefault();

            if (busy) {
                return;
            }

            const text = input.value.trim();

            if (!text) {
                return;
            }

            input.value = '';

            const inputMode = nextInputMode;
            nextInputMode = 'text';

            await sendMessage(
                text,
                inputMode
            );
        }
    );

    input.addEventListener(
        'keydown',
        (event) => {
            if (
                event.key === 'Enter'
                && !event.shiftKey
            ) {
                event.preventDefault();
                form.requestSubmit();
            }
        }
    );

    initialiseVoice();

    input.focus();
    scrollToBottom();
})();
</script>

<?php endif; ?>
