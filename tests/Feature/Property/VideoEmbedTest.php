<?php

declare(strict_types=1);

use App\Models\Property;

it('recognises a youtube watch URL', function (): void {
    $p = new Property(['video_url' => 'https://www.youtube.com/watch?v=abcDEF12345']);
    $embed = $p->videoEmbed();
    expect($embed['kind'])->toBe('youtube');
    expect($embed['embed_url'])->toContain('youtube-nocookie.com/embed/abcDEF12345');
    expect($embed['poster'])->toContain('i.ytimg.com/vi/abcDEF12345');
});

it('recognises a youtu.be short URL', function (): void {
    $p = new Property(['video_url' => 'https://youtu.be/abcDEF12345']);
    expect($p->videoEmbed()['kind'])->toBe('youtube');
});

it('recognises a vimeo URL', function (): void {
    $p = new Property(['video_url' => 'https://vimeo.com/123456789']);
    $embed = $p->videoEmbed();
    expect($embed['kind'])->toBe('vimeo');
    expect($embed['embed_url'])->toBe('https://player.vimeo.com/video/123456789?autoplay=1&title=0&byline=0&portrait=0');
});

it('recognises a direct .mp4 URL', function (): void {
    $p = new Property(['video_url' => 'https://cdn.example.com/tour.mp4']);
    $embed = $p->videoEmbed();
    expect($embed['kind'])->toBe('file');
    expect($embed['src'])->toBe('https://cdn.example.com/tour.mp4');
});

it('recognises a .mp4 URL with a query string', function (): void {
    $p = new Property(['video_url' => 'https://keverna-file.dokwe.com/tenant_9/VIDEO-2026-09-22-16-49-20.mp4?x=1']);
    expect($p->videoEmbed()['kind'])->toBe('file');
});

it('rejects unknown host / non-video URLs', function (): void {
    $p = new Property(['video_url' => 'https://example.com/notavideo']);
    expect($p->videoEmbed())->toBeNull();
});

it('returns null on empty', function (): void {
    $p = new Property(['video_url' => '']);
    expect($p->videoEmbed())->toBeNull();
});
