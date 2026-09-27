<?php

declare(strict_types=1);

// Bump PHP memory for the test suite — the §18 admin work pushed the
// composite footprint past the default 128M when running the full suite
// (Livewire + Spatie multitenancy + Spatie media all loaded together
// across many HTTP-flow tests). 512M is comfortably under what CI VMs
// expose; bump again if a future section pushes past it.
ini_set('memory_limit', '512M');

require __DIR__.'/../vendor/autoload.php';
