<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact Us</title>
    @include('partials.pwa-head')
</head>
<body>
    <div>
        <h1>Contact Us</h1>
        <p>If you have any questions or inquiries, please feel free to contact us at:</p>
        <ul>
            <li>name: {{ request()->name }}</li>
            <li>email: {{ request()->email }}</li>
        </ul>
    </div>
</body>
</html>
