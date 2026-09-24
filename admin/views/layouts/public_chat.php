<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta name="robots" content="noindex,nofollow">

    <title>
        <?= htmlspecialchars(
            $title ?? 'AI Assistant',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            min-height: 100vh;
            background:
                linear-gradient(
                    135deg,
                    #f8f9fa 0%,
                    #eef2f6 100%
                );
        }

        .chat-shell {
            max-width: 920px;
            height: calc(100vh - 40px);
            min-height: 620px;
        }

        .chat-card {
            height: 100%;
            overflow: hidden;
        }

        #chatMessages {
            overflow-y: auto;
            background: #f8f9fa;
        }

        .chat-bubble {
            max-width: 78%;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .chat-user {
            margin-left: auto;
        }

        .chat-assistant {
            margin-right: auto;
        }

        .source-card {
            font-size: .78rem;
        }

        .typing-dot {
            animation: pulse 1.2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: .35; }
            50% { opacity: 1; }
        }
    </style>
</head>

<body>

<?= $content ?? '' ?>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>
