<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Multistream Monitor</title>
    @vite('resources/css/app.css')
    @livewireStyles
    <style>
        html, body { margin: 0; padding: 0; min-height: 100%; background: #0a0a0a; }
    </style>
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
