@props([
    'article',
])

<section style="background:#ffffff; padding:0 0 72px 0;">
    <div style="max-width:860px; margin:0 auto; padding:0 16px;">
        <article class="article-content">
            {!! $article->content !!}
        </article>
    </div>
</section>

<style>
    .article-content {
        color: #334155;
        font-size: 17px;
        line-height: 1.9;
        font-weight: 400;
        word-break: break-word;
    }

    .article-content > *:first-child {
        margin-top: 0 !important;
    }

    .article-content > *:last-child {
        margin-bottom: 0 !important;
    }

    .article-content p {
        margin: 0 0 1.25em 0;
        color: #475569;
        font-size: 17px;
        line-height: 1.9;
    }

    .article-content h1,
    .article-content h2,
    .article-content h3,
    .article-content h4,
    .article-content h5,
    .article-content h6 {
        color: #0f172a;
        font-weight: 700;
        line-height: 1.3;
        margin-top: 1.8em;
        margin-bottom: 0.8em;
        letter-spacing: -0.02em;
    }

    .article-content h1 {
        font-size: 34px;
    }

    .article-content h2 {
        font-size: 28px;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 10px;
    }

    .article-content h3 {
        font-size: 22px;
    }

    .article-content h4 {
        font-size: 18px;
    }

    .article-content ul,
    .article-content ol {
        margin: 0 0 1.5em 0;
        padding-left: 1.5em;
    }

    .article-content ul {
        list-style-type: disc !important;
    }

    .article-content ol {
        list-style-type: decimal !important;
    }

    .article-content li {
        margin-bottom: 0.65em;
        color: #475569;
        line-height: 1.85;
        display: list-item !important;
    }

    .article-content ul li::marker,
    .article-content ol li::marker {
        color: #696FC7;
        font-weight: 700;
    }

    .article-content strong {
        color: #0f172a;
        font-weight: 700;
    }

    .article-content em {
        font-style: italic;
    }

    .article-content a {
        color: #696FC7;
        text-decoration: none;
        font-weight: 600;
    }

    .article-content a:hover {
        text-decoration: underline;
    }

    .article-content blockquote {
        margin: 1.75em 0;
        padding: 16px 20px;
        background: #f8fafc;
        border-left: 4px solid #696FC7;
        border-radius: 12px;
        color: #334155;
        font-style: italic;
    }

    .article-content img {
        display: block;
        max-width: 100%;
        height: auto;
        margin: 24px auto;
        border-radius: 16px;
    }

    .article-content hr {
        border: 0;
        border-top: 1px solid #e2e8f0;
        margin: 2em 0;
    }

    .article-content table {
        width: 100%;
        border-collapse: collapse;
        margin: 1.5em 0;
        overflow-x: auto;
        display: block;
    }

    .article-content th,
    .article-content td {
        border: 1px solid #e2e8f0;
        padding: 12px 14px;
        text-align: left;
    }

    .article-content th {
        background: #f8fafc;
        color: #0f172a;
        font-weight: 600;
    }

    .article-content code {
        background: #f1f5f9;
        color: #0f172a;
        padding: 2px 6px;
        border-radius: 6px;
        font-size: 14px;
    }

    .article-content pre {
        background: #0f172a;
        color: #f8fafc;
        padding: 18px;
        border-radius: 14px;
        overflow-x: auto;
        margin: 1.5em 0;
    }

    .article-content pre code {
        background: transparent;
        color: inherit;
        padding: 0;
    }

    @media (max-width: 640px) {
        .article-content {
            font-size: 16px;
            line-height: 1.85;
        }

        .article-content p,
        .article-content li {
            font-size: 16px;
        }

        .article-content h1 {
            font-size: 28px;
        }

        .article-content h2 {
            font-size: 24px;
        }

        .article-content h3 {
            font-size: 20px;
        }
    }
</style>