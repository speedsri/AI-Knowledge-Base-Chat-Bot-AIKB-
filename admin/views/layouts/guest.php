<?php use function App\Core\e; ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'AI Knowledge Base') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-8 col-md-6 col-lg-4">
                <div class="text-center my-5">
                    <h1 class="h4 fw-semibold">AI Knowledge Base</h1>
                    <p class="text-secondary small">Agriculture &amp; Dynamic Technologies</p>
                </div>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <?= $content ?? '' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
