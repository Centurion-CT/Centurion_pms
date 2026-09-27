<?php

declare(strict_types=1);

it('boots the application and serves the root route', function (): void {
    $response = $this->get('/');

    $response->assertStatus(200);
});
