(() => {
    'use strict';

    const script = document.currentScript;
    if (!script) return;

    const apiBase = (
        script.dataset.api ||
        new URL(script.src).origin
    ).replace(/\/+$/, '');

    const title =
        script.dataset.title ||
        'AI Assistant';

    const greeting =
        script.dataset.greeting ||
        'Hello. How can I help you today?';

    const position =
        script.dataset.position === 'left'
            ? 'left'
            : 'right';

    const language =
        script.dataset.language || 'en-US';

    const voiceEnabled =
        !['0', 'false', 'off', 'no'].includes(
            String(
                script.dataset.voice ?? 'true'
            ).trim().toLowerCase()
        );

    const storageKey =
        'aikb_widget_conversation_ref';

    function uuid() {
        if (
            window.crypto &&
            typeof window.crypto.randomUUID === 'function'
        ) {
            return window.crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'
            .replace(/[xy]/g, c => {
                const r = Math.random() * 16 | 0;
                const v = c === 'x'
                    ? r
                    : (r & 0x3 | 0x8);

                return v.toString(16);
            });
    }

    let conversationRef =
        localStorage.getItem(storageKey);

    if (!conversationRef) {
        conversationRef = 'widget-' + uuid();
        localStorage.setItem(
            storageKey,
            conversationRef
        );
    }

    const host = document.createElement('div');
    host.id = 'aikb-widget-host';

    const shadow = host.attachShadow({
        mode: 'open'
    });

    const style = document.createElement('style');

    style.textContent = `
        :host {
            all: initial;
        }

        * {
            box-sizing: border-box;
        }

        .launcher {
            position: fixed;
            bottom: 24px;
            ${position}: 24px;
            width: 58px;
            height: 58px;
            border: 0;
            border-radius: 50%;
            background: #0d6efd;
            color: #fff;
            font-size: 25px;
            cursor: pointer;
            box-shadow: 0 8px 24px rgba(0,0,0,.22);
            z-index: 2147483646;
        }

        .panel {
            position: fixed;
            bottom: 94px;
            ${position}: 24px;
            width: min(390px, calc(100vw - 32px));
            height: min(620px, calc(100vh - 125px));
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 18px 48px rgba(0,0,0,.24);
            overflow: hidden;
            display: none;
            flex-direction: column;
            font-family:
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
            z-index: 2147483646;
        }

        .panel.open {
            display: flex;
        }

        .header {
            background: #0d6efd;
            color: #fff;
            padding: 15px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-title {
            flex: 1;
            font-weight: 700;
            font-size: 15px;
        }

        .header-button,
        .close {
            border: 0;
            background: transparent;
            color: #fff;
            cursor: pointer;
            line-height: 1;
        }

        .header-button {
            font-size: 20px;
            padding: 4px 6px;
        }

        .close {
            font-size: 25px;
        }

        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            background: #f5f7fa;
        }

        .row {
            display: flex;
            margin-bottom: 12px;
        }

        .row.user {
            justify-content: flex-end;
        }

        .bubble {
            max-width: 82%;
            padding: 10px 12px;
            border-radius: 15px;
            font-size: 14px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .assistant .bubble {
            background: #fff;
            border: 1px solid #e1e6eb;
            color: #212529;
        }

        .user .bubble {
            background: #0d6efd;
            color: #fff;
        }

        .composer {
            border-top: 1px solid #e8eaed;
            padding: 10px;
            background: #fff;
            display: flex;
            gap: 7px;
            align-items: flex-end;
        }

        textarea {
            flex: 1;
            min-height: 42px;
            max-height: 110px;
            resize: none;
            border: 1px solid #ced4da;
            border-radius: 12px;
            padding: 10px;
            font: inherit;
            outline: none;
        }

        textarea:focus {
            border-color: #86b7fe;
        }

        .voice,
        .send {
            height: 42px;
            border: 0;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
        }

        .voice {
            min-width: 42px;
            padding: 0 10px;
            background: #f1f3f5;
            color: #212529;
            font-size: 18px;
        }

        .voice.listening {
            background: #dc3545;
            color: #fff;
        }

        .send {
            padding: 0 15px;
            background: #0d6efd;
            color: #fff;
        }

        button:disabled {
            opacity: .55;
            cursor: default;
        }

        .status {
            padding: 0 12px 10px;
            background: #fff;
            color: #6c757d;
            font-size: 12px;
            min-height: 20px;
        }

        .status.error {
            color: #dc3545;
        }

        @media (max-width: 480px) {
            .panel {
                left: 8px;
                right: 8px;
                bottom: 82px;
                width: auto;
                height: calc(100vh - 100px);
                border-radius: 14px;
            }

            .launcher {
                bottom: 16px;
                ${position}: 16px;
            }
        }
    `;

    const launcher =
        document.createElement('button');

    launcher.className = 'launcher';
    launcher.type = 'button';
    launcher.setAttribute(
        'aria-label',
        'Open AI assistant'
    );
    launcher.textContent = '✦';

    const panel =
        document.createElement('section');

    panel.className = 'panel';

    const header =
        document.createElement('div');

    header.className = 'header';

    const headerTitle =
        document.createElement('div');

    headerTitle.className = 'header-title';
    headerTitle.textContent = title;

    const speaker =
        document.createElement('button');

    speaker.className = 'header-button';
    speaker.type = 'button';
    speaker.title = 'Spoken replies enabled';
    speaker.setAttribute(
        'aria-label',
        'Toggle spoken replies'
    );
    speaker.textContent = '🔊';

    const close =
        document.createElement('button');

    close.className = 'close';
    close.type = 'button';
    close.setAttribute(
        'aria-label',
        'Close AI assistant'
    );
    close.textContent = '×';

    header.appendChild(headerTitle);
    header.appendChild(speaker);
    header.appendChild(close);

    const messages =
        document.createElement('div');

    messages.className = 'messages';

    const composer =
        document.createElement('form');

    composer.className = 'composer';

    const input =
        document.createElement('textarea');

    input.rows = 1;
    input.maxLength = 4000;
    input.placeholder = 'Type your message...';

    const mic =
        document.createElement('button');

    mic.className = 'voice';
    mic.type = 'button';
    mic.title = 'Speak your message';
    mic.setAttribute(
        'aria-label',
        'Speak your message'
    );
    mic.textContent = '🎤';

    const send =
        document.createElement('button');

    send.className = 'send';
    send.type = 'submit';
    send.textContent = 'Send';

    const status =
        document.createElement('div');

    status.className = 'status';

    composer.appendChild(input);
    composer.appendChild(mic);
    composer.appendChild(send);

    panel.appendChild(header);
    panel.appendChild(messages);
    panel.appendChild(composer);
    panel.appendChild(status);

    shadow.appendChild(style);
    shadow.appendChild(launcher);
    shadow.appendChild(panel);

    document.body.appendChild(host);

    let busy = false;
    let recognition = null;
    let listening = false;
    let speechEnabled = true;
    let nextInputMode = 'text';

    function setStatus(message, error = false) {
        status.textContent = message || '';
        status.classList.toggle(
            'error',
            Boolean(error)
        );
    }

    function addMessage(role, text) {
        const row =
            document.createElement('div');

        row.className = 'row ' + role;

        const bubble =
            document.createElement('div');

        bubble.className = 'bubble';
        bubble.textContent = text;

        row.appendChild(bubble);
        messages.appendChild(row);

        messages.scrollTop =
            messages.scrollHeight;
    }

    function stopSpeaking() {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
    }

    function speakAnswer(text) {
        if (
            !speechEnabled ||
            !text ||
            !('speechSynthesis' in window)
        ) {
            return;
        }

        stopSpeaking();

        const utterance =
            new SpeechSynthesisUtterance(text);

        utterance.lang = language;
        utterance.rate = 1;

        utterance.onerror = () => {
            setStatus(
                'The browser could not play the voice response.',
                true
            );
        };

        window.speechSynthesis.speak(
            utterance
        );
    }

    function updateSpeaker() {
        speaker.textContent =
            speechEnabled ? '🔊' : '🔇';

        speaker.title =
            speechEnabled
                ? 'Spoken replies enabled'
                : 'Spoken replies muted';
    }

    function setListening(state) {
        listening = state;

        mic.classList.toggle(
            'listening',
            state
        );

        mic.textContent =
            state ? '■' : '🎤';

        mic.title =
            state
                ? 'Listening — click to stop'
                : 'Speak your message';

        if (state) {
            setStatus(
                'Listening... speak now.'
            );
        }
    }

    function initialiseVoice() {
        if (!window.isSecureContext) {
            mic.disabled = true;

            setStatus(
                'Microphone requires HTTPS. Text chat is available.',
                true
            );

            return;
        }

        if (!('speechSynthesis' in window)) {
            speaker.disabled = true;
        }

        const Recognition =
            window.SpeechRecognition ||
            window.webkitSpeechRecognition;

        if (!Recognition) {
            mic.disabled = true;

            setStatus(
                'Voice recognition is not supported by this browser. Text chat is available.'
            );

            return;
        }

        recognition = new Recognition();

        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;
        recognition.lang = language;

        recognition.onstart = () => {
            setListening(true);
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
            setListening(false);

            let message =
                'Voice recognition failed. Please try again.';

            if (event.error === 'not-allowed') {
                message =
                    'Microphone permission was denied. Please allow microphone access.';
            } else if (
                event.error === 'no-speech'
            ) {
                message =
                    'No speech was detected. Please try again.';
            } else if (
                event.error === 'audio-capture'
            ) {
                message =
                    'No microphone is available.';
            }

            setStatus(message, true);
        };

        recognition.onend = () => {
            const shouldSend =
                nextInputMode === 'voice' &&
                input.value.trim() !== '';

            setListening(false);

            if (shouldSend && !busy) {
                setStatus(
                    'Voice captured. Sending...'
                );

                setTimeout(
                    () => composer.requestSubmit(),
                    100
                );
            } else if (!busy) {
                setStatus(
                    'Press the microphone to speak.'
                );
            }
        };

        mic.addEventListener(
            'click',
            () => {
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
                    setStatus(
                        'Microphone could not start. Please try again.',
                        true
                    );
                }
            }
        );

        setStatus(
            'Press the microphone to speak.'
        );
    }

    addMessage(
        'assistant',
        greeting
    );

    launcher.addEventListener(
        'click',
        () => {
            panel.classList.add('open');
            input.focus();
        }
    );

    close.addEventListener(
        'click',
        () => {
            if (listening && recognition) {
                recognition.stop();
            }

            stopSpeaking();
            panel.classList.remove('open');
        }
    );

    speaker.addEventListener(
        'click',
        () => {
            speechEnabled = !speechEnabled;

            if (!speechEnabled) {
                stopSpeaking();
            }

            updateSpeaker();
        }
    );

    async function sendMessage(
        text,
        inputMode = 'text'
    ) {
        busy = true;
        send.disabled = true;
        mic.disabled = true;
        input.disabled = true;

        setStatus(
            'Assistant is thinking...'
        );

        addMessage('user', text);

        try {
            const body =
                new URLSearchParams();

            body.set('message', text);

            body.set(
                'conversation_ref',
                conversationRef
            );

            body.set(
                'input_mode',
                inputMode
            );

            const response =
                await fetch(
                    apiBase + '/widget/message',
                    {
                        method: 'POST',
                        mode: 'cors',
                        credentials: 'omit',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded;charset=UTF-8',
                            'Accept':
                                'application/json'
                        },
                        body: body.toString()
                    }
                );

            const data =
                await response.json();

            if (
                !response.ok ||
                data.ok !== true
            ) {
                if (
                    data.error ===
                    'too_many_requests'
                ) {
                    throw new Error(
                        'Too many messages. Please try again later.'
                    );
                }

                throw new Error(
                    'The assistant could not answer right now.'
                );
            }

            const answer =
                data.answer ||
                'No answer returned.';

            addMessage(
                'assistant',
                answer
            );

            setStatus('');

            if (
                inputMode === 'voice' &&
                speechEnabled
            ) {
                speakAnswer(answer);
            }
        } catch (error) {
            setStatus(
                error instanceof Error
                    ? error.message
                    : 'Something went wrong.',
                true
            );
        } finally {
            busy = false;
            send.disabled = false;
            input.disabled = false;

            if (recognition) {
                mic.disabled = false;
            }

            input.focus();
        }
    }

    composer.addEventListener(
        'submit',
        async event => {
            event.preventDefault();

            if (busy) {
                return;
            }

            const text =
                input.value.trim();

            if (!text) {
                return;
            }

            input.value = '';

            const inputMode =
                nextInputMode;

            nextInputMode = 'text';

            await sendMessage(
                text,
                inputMode
            );
        }
    );

    input.addEventListener(
        'keydown',
        event => {
            if (
                event.key === 'Enter' &&
                !event.shiftKey
            ) {
                event.preventDefault();
                composer.requestSubmit();
            }
        }
    );

    updateSpeaker();
    if (voiceEnabled) {
        initialiseVoice();
    } else {
        if (typeof micButton !== 'undefined' && micButton) {
            micButton.disabled = true;
            micButton.style.display = 'none';
        }

        if (typeof speakerButton !== 'undefined' && speakerButton) {
            speakerButton.disabled = true;
            speakerButton.style.display = 'none';
        }

        if (typeof voiceStatus !== 'undefined' && voiceStatus) {
            voiceStatus.textContent = '';
            voiceStatus.style.display = 'none';
        }
    }
})();
