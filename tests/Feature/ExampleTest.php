<?php

declare(strict_types=1);

it('boots the application and sends the root to the staff sign-in', function (): void {
    $this->get('/')->assertRedirect();
});
