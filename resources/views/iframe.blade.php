<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Iframe Embed Test</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: #000;
            overflow: hidden;
        }
        .frame-wrap {
            position: fixed;
            inset: 0;
        }
        iframe {
            border: 0;
            width: 100%;
            height: 100%;
            display: block;
        }
    </style>
</head>
<body>
    <div class="frame-wrap">
        <iframe
            src="{{ $src }}"
            allow="autoplay; fullscreen; encrypted-media; picture-in-picture"
            allowfullscreen
            referrerpolicy="no-referrer-when-downgrade"
        ></iframe>
    </div>
</body>
</html>
