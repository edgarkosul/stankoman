<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail Previews</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f2eb;
            --panel: #fffdf9;
            --border: #e5d7c3;
            --text: #23170f;
            --muted: #726255;
            --accent: #c65a11;
            --accent-soft: #ffe6d2;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--text);
            background:
                radial-gradient(circle at top right, #ffe2c2 0, transparent 24%),
                radial-gradient(circle at top left, #fff4dd 0, transparent 28%),
                var(--bg);
        }
        .wrap {
            max-width: 1080px;
            margin: 0 auto;
            padding: 40px 20px 64px;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 42px;
            line-height: 1.05;
        }
        p.lead {
            margin: 0 0 32px;
            max-width: 760px;
            font-size: 18px;
            line-height: 1.6;
            color: var(--muted);
        }
        code {
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 14px;
        }
        .group {
            margin-top: 28px;
            padding: 24px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 22px;
            box-shadow: 0 14px 36px rgba(35, 23, 15, 0.06);
        }
        .group h2 {
            margin: 0 0 16px;
            font-size: 24px;
        }
        ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 12px;
        }
        a.card {
            display: block;
            padding: 15px 16px;
            border-radius: 16px;
            border: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            background: linear-gradient(180deg, #ffffff 0%, #fff7ef 100%);
            transition: transform .15s ease, border-color .15s ease, background .15s ease;
        }
        a.card:hover {
            transform: translateY(-1px);
            border-color: var(--accent);
            background: linear-gradient(180deg, #ffffff 0%, var(--accent-soft) 100%);
        }
        .label {
            display: block;
            font-size: 18px;
            font-weight: 700;
        }
        .key {
            display: block;
            margin-top: 6px;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 13px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <main class="wrap">
        <h1>Mail Previews</h1>
        <p class="lead">
            Локальный каталог email-шаблонов проекта. Страницы доступны только в окружениях
            <code>local</code> и <code>testing</code> и рендерят те же Blade и Markdown шаблоны,
            которые используются при реальной отправке.
        </p>

        @foreach ($groups as $group => $items)
            <section class="group">
                <h2>{{ $group }}</h2>
                <ul>
                    @foreach ($items as $item)
                        <li>
                            <a class="card" href="{{ route('mail.preview.show', $item['key']) }}">
                                <span class="label">{{ $item['label'] }}</span>
                                <span class="key">{{ $item['key'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </main>
</body>
</html>
