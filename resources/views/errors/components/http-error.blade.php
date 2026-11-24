@php
    $status = $exception->getStatusCode();
    $message = \Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Error';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $message }}</title>
    @vite('resources/css/pages/errors.css')
</head>
<body class="http-error-page">
    <main class="http-error" aria-label="{{ $message }}">
        <h1 class="http-error__code">{{ $status }}</h1>
        <p class="http-error__message">{{ $message }}</p>
    </main>
</body>
</html>
