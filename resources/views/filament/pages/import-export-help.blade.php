<x-filament-panels::page>
    @once
        <style>
            .github-readme .markdown-body {
                color: #1f2328;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif;
                font-size: 16px;
                line-height: 1.6;
                word-wrap: break-word;
            }

            .github-readme .markdown-body > :first-child {
                margin-top: 0;
            }

            .github-readme .markdown-body > :last-child {
                margin-bottom: 0;
            }

            .github-readme .markdown-body h1,
            .github-readme .markdown-body h2,
            .github-readme .markdown-body h3,
            .github-readme .markdown-body h4,
            .github-readme .markdown-body h5,
            .github-readme .markdown-body h6 {
                margin-top: 24px;
                margin-bottom: 16px;
                font-weight: 600;
                line-height: 1.25;
            }

            .github-readme .markdown-body h1,
            .github-readme .markdown-body h2 {
                border-bottom: 1px solid #d0d7de;
                padding-bottom: 0.35em;
            }

            .github-readme .markdown-body h1 {
                font-size: 2em;
            }

            .github-readme .markdown-body h2 {
                font-size: 1.5em;
            }

            .github-readme .markdown-body h3 {
                font-size: 1.25em;
            }

            .github-readme .markdown-body p,
            .github-readme .markdown-body ul,
            .github-readme .markdown-body ol,
            .github-readme .markdown-body table,
            .github-readme .markdown-body blockquote,
            .github-readme .markdown-body pre {
                margin-top: 0;
                margin-bottom: 16px;
            }

            .github-readme .markdown-body ul,
            .github-readme .markdown-body ol {
                padding-left: 2em;
            }

            .github-readme .markdown-body li {
                margin-top: 0.25em;
            }

            .github-readme .markdown-body a {
                color: #0969da;
                text-decoration: none;
            }

            .github-readme .markdown-body a:hover {
                text-decoration: underline;
            }

            .github-readme .markdown-body code {
                border-radius: 6px;
                background-color: #f6f8fa;
                padding: 0.2em 0.4em;
                font-family: ui-monospace, SFMono-Regular, SF Mono, Menlo, Consolas, "Liberation Mono", monospace;
                font-size: 85%;
            }

            .github-readme .markdown-body pre {
                overflow: auto;
                border-radius: 8px;
                background-color: #f6f8fa;
                padding: 16px;
                font-size: 85%;
                line-height: 1.45;
            }

            .github-readme .markdown-body pre code {
                padding: 0;
                background: transparent;
            }

            .github-readme .markdown-body blockquote {
                border-left: 0.25em solid #d0d7de;
                padding: 0 1em;
                color: #59636e;
            }

            .github-readme .markdown-body hr {
                height: 0.25em;
                margin: 24px 0;
                border: 0;
                background-color: #d0d7de;
            }

            .github-readme .markdown-body table {
                display: block;
                width: max-content;
                max-width: 100%;
                overflow: auto;
                border-spacing: 0;
                border-collapse: collapse;
            }

            .github-readme .markdown-body table th,
            .github-readme .markdown-body table td {
                border: 1px solid #d0d7de;
                padding: 6px 13px;
            }

            .github-readme .markdown-body table tr {
                border-top: 1px solid #d0d7de;
                background-color: #ffffff;
            }

            .github-readme .markdown-body table tr:nth-child(2n) {
                background-color: #f6f8fa;
            }

            .dark .github-readme .markdown-body {
                color: #c9d1d9;
            }

            .dark .github-readme .markdown-body h1,
            .dark .github-readme .markdown-body h2 {
                border-bottom-color: #3d444d;
            }

            .dark .github-readme .markdown-body a {
                color: #58a6ff;
            }

            .dark .github-readme .markdown-body code,
            .dark .github-readme .markdown-body pre {
                background-color: #161b22;
            }

            .dark .github-readme .markdown-body blockquote {
                border-left-color: #3d444d;
                color: #8b949e;
            }

            .dark .github-readme .markdown-body hr {
                background-color: #3d444d;
            }

            .dark .github-readme .markdown-body table th,
            .dark .github-readme .markdown-body table td,
            .dark .github-readme .markdown-body table tr {
                border-color: #3d444d;
            }

            .dark .github-readme .markdown-body table tr {
                background-color: #0d1117;
            }

            .dark .github-readme .markdown-body table tr:nth-child(2n) {
                background-color: #161b22;
            }
        </style>
    @endonce

    <div class="mx-auto w-full max-w-5xl">
        <article class="github-readme rounded-2xl border border-zinc-200 bg-white px-6 py-7 shadow-sm sm:px-8 sm:py-9 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="markdown-body">
                {!! $guideHtml !!}
            </div>
        </article>
    </div>
</x-filament-panels::page>
