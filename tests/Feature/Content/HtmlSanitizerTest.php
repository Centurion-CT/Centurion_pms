<?php

declare(strict_types=1);

use App\Services\Content\HtmlSanitizer;

it('keeps the allow-listed inline tags with no attributes', function (): void {
    $svc = new HtmlSanitizer;

    $html = '<p>Welcome to <strong>Grand Palace</strong> — a <em>boutique</em> stay with <u>24/7</u> power.</p>';
    expect($svc->sanitize($html))->toBe($html);
});

it('keeps unordered and ordered lists intact', function (): void {
    $svc = new HtmlSanitizer;

    $html = '<ul><li>Wi-Fi</li><li>Breakfast</li></ul>';
    expect($svc->sanitize($html))->toBe($html);

    $html = '<ol><li>Check in</li><li>Sleep</li></ol>';
    expect($svc->sanitize($html))->toBe($html);
});

it('unwraps disallowed elements but keeps their text', function (): void {
    $svc = new HtmlSanitizer;

    $html = '<div class="foo"><span style="color:red">hello</span></div>';
    expect($svc->sanitize($html))->toBe('hello');
});

it('strips script tags and their content entirely', function (): void {
    $svc = new HtmlSanitizer;

    $html = 'safe<script>alert(1)</script>safe';
    // script is not on the allow-list → unwrapped; contents were
    // text so they survive as literal text (rendered, not executed).
    expect($svc->sanitize($html))->toBe('safealert(1)safe');
});

it('strips on-event handlers on allowed tags', function (): void {
    $svc = new HtmlSanitizer;

    $html = '<a href="https://example.com" onclick="alert(1)" onmouseover="steal()">click</a>';
    $out = $svc->sanitize($html);
    expect($out)->not->toContain('onclick');
    expect($out)->not->toContain('onmouseover');
    expect($out)->toContain('href="https://example.com"');
});

it('forces safe rel + target on every anchor', function (): void {
    $svc = new HtmlSanitizer;

    $out = $svc->sanitize('<a href="https://example.com">go</a>');
    expect($out)->toContain('rel="noopener nofollow ugc"');
    expect($out)->toContain('target="_blank"');
});

it('strips dangerous URL schemes from anchors', function (): void {
    $svc = new HtmlSanitizer;

    // javascript: → href attribute dropped, but rel/target still forced.
    $out = $svc->sanitize('<a href="javascript:alert(1)">bad</a>');
    expect($out)->not->toContain('href=');
    expect($out)->toContain('rel="noopener nofollow ugc"');

    // Same for data: URLs.
    $out2 = $svc->sanitize('<a href="data:text/html,<script>alert(1)</script>">bad</a>');
    expect($out2)->not->toContain('href=');
});

it('accepts mailto: and tel: schemes as safe', function (): void {
    $svc = new HtmlSanitizer;

    expect($svc->sanitize('<a href="mailto:hi@example.com">Email</a>'))
        ->toContain('href="mailto:hi@example.com"');
    expect($svc->sanitize('<a href="tel:+2348012345678">Call</a>'))
        ->toContain('href="tel:+2348012345678"');
});

it('handles null and empty input as empty string', function (): void {
    $svc = new HtmlSanitizer;
    expect($svc->sanitize(null))->toBe('');
    expect($svc->sanitize(''))->toBe('');
    expect($svc->sanitize('   '))->toBe('');
});

it('preserves multibyte characters (naira sign, emoji)', function (): void {
    $svc = new HtmlSanitizer;

    $html = '<p>₦45,000 per night 🌙 — great value.</p>';
    expect($svc->sanitize($html))->toBe($html);
});
