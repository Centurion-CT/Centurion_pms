<?php
    $tenant = \App\Models\Tenant::current();
    $property = $tenant?->property;
    $logoUrl = $property?->getFirstMedia('logo')?->getUrl();

    // Per-tenant font theme, operator picks display + body pair from a
    // small whitelist. Falls through to Playfair + Inter if unset.
    $fontMap = [
        'inter_playfair'          => ['display' => 'Playfair Display', 'body' => 'Inter'],
        'inter_inter'             => ['display' => 'Inter',            'body' => 'Inter'],
        'lora_lora'               => ['display' => 'Lora',             'body' => 'Lora'],
        'poppins_poppins'         => ['display' => 'Poppins',          'body' => 'Poppins'],
        'merriweather_montserrat' => ['display' => 'Merriweather',     'body' => 'Montserrat'],
    ];
    $fonts = $fontMap[$property?->font_theme ?? 'inter_playfair'] ?? $fontMap['inter_playfair'];
    $primary = $property?->primary_color ?? '#14171A';

    // Same arrow used by every pill CTA in the system.
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';

    // Property-kind-aware nav copy. Defaults to the stay flow so any
    // page rendered without a property still gets a sensible nav (e.g.
    // /workspaces hit on a tenant whose property type is Hotel, the
    // route exists for everyone, but the label tracks the actual type).
    $publicKind = $property?->type?->kind() ?? 'stay';
    [$navBrowseRoute, $navBrowseLabel, $navInventoryLabel] = match ($publicKind) {
        'workspace'   => ['tenant.workspaces.browse', 'See plans',     'Workspaces'],
        'residential' => ['tenant.units.browse',      'Browse homes',  'Homes'],
        default       => ['tenant.rooms.browse',      'Book a stay',   'Rooms'],
    };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo e($title ?? $tenant?->name ?? 'Keverna'); ?></title>
    <?php echo $__env->make('partials.favicons', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=<?php echo e(str_replace(' ', '+', $fonts['display'])); ?>:wght@500;600;700&family=<?php echo e(str_replace(' ', '+', $fonts['body'])); ?>:wght@400;500;600&display=swap">

    <style>
        :root {
            --primary:      <?php echo e($primary); ?>;
            --primary-dark: color-mix(in srgb, <?php echo e($primary); ?> 78%, black);
            --primary-soft: color-mix(in srgb, <?php echo e($primary); ?> 12%, transparent);

            --paper:    #FAF7F2;
            --paper-2:  #F2EEE6;
            --ink:      #14171A;
            --ink-soft: #3D434A;
            --ink-mute: #7A7F87;
            --line:     rgba(20, 23, 26, 0.10);
            --line-soft:rgba(20, 23, 26, 0.06);

            --display-font: '<?php echo e($fonts['display']); ?>', Georgia, serif;
            --body-font:    '<?php echo e($fonts['body']); ?>', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: var(--body-font);
            color: var(--ink);
            background: var(--paper);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        a { color: inherit; text-decoration: none; }
        a:hover { text-decoration: none; }
        img { display: block; max-width: 100%; }
        h1, h2, h3 {
            font-family: var(--display-font);
            color: var(--ink);
            line-height: 1.06;
            letter-spacing: -0.018em;
            margin: 0;
            font-weight: 600;
        }

        /* ── Sticky navigation ──────────────────────────────────────
           Cream-paper blurred backdrop. The brand mark sits on the
           left, link list in the middle, pill CTA (white pill with
           primary accent dot) on the right. Matches the property
           landing page so a guest never feels they crossed a brand
           boundary moving from `/` to `/rooms/{id}`. */
        .nav {
            position: sticky; top: 0; z-index: 50;
            background: color-mix(in srgb, var(--paper) 90%, transparent);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid var(--line-soft);
        }
        .nav-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0.9rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            gap: 1.5rem;
        }
        @media (min-width: 768px) { .nav-inner { padding: 1rem 2.5rem; } }
        .nav-brand {
            display: inline-flex; align-items: center; gap: 0.7rem;
            font-family: var(--display-font);
            font-size: 17px; font-weight: 600;
            color: var(--ink); letter-spacing: -0.01em;
            min-width: 0;
        }
        .nav-brand img { height: 28px; width: auto; flex-shrink: 0; }
        .nav-brand span {
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 280px;
        }
        .nav-links {
            display: flex; gap: 2rem;
            font-size: 13.5px;
            color: var(--ink-soft);
        }
        .nav-links a {
            position: relative;
            padding: 4px 0;
            transition: color 160ms;
        }
        .nav-links a:hover { color: var(--ink); }
        @media (max-width: 880px) { .nav-links { display: none; } }

        .nav-cta {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 6px 7px 6px 18px;
            background: #fff;
            color: var(--ink);
            border-radius: 9999px;
            border: 1px solid rgba(20, 23, 26, 0.06);
            box-shadow: 0 1px 0 rgba(20,23,26,0.04);
            font-family: var(--body-font);
            font-size: 13px; font-weight: 500;
            transition: border-color 200ms, transform 80ms;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .nav-cta:hover { border-color: var(--ink); transform: translateY(-1px); }
        .nav-cta:active { transform: translateY(0); }
        .nav-cta .dot {
            width: 28px; height: 28px;
            background: var(--primary);
            color: #fff;
            border-radius: 9999px;
            display: inline-flex; align-items: center; justify-content: center;
        }

        /* ── Footer ─────────────────────────────────────────────────
           Quiet cream footer with a top rule. Property name sits in
           the display face; "Powered by Keverna" stays small and
           muted so the operator's brand reads first. */
        .site-footer {
            margin-top: 4rem;
            padding: 2.75rem 1.5rem 2.25rem;
            background: var(--paper);
            border-top: 1px solid var(--line-soft);
            color: var(--ink-mute);
            font-size: 13px;
        }
        .site-footer .wrap {
            max-width: 1200px;
            margin: 0 auto;
            display: flex; flex-direction: column; align-items: center;
            gap: 0.65rem;
            text-align: center;
        }
        .site-footer .brand-line {
            font-family: var(--display-font);
            font-size: 16px;
            font-weight: 500;
            color: var(--ink);
            letter-spacing: -0.005em;
        }
        .site-footer .meta {
            font-size: 11.5px;
            color: var(--ink-mute);
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .site-footer .meta a {
            color: var(--ink);
            text-decoration: underline;
            text-underline-offset: 4px;
            text-decoration-thickness: 1px;
            text-transform: none;
            letter-spacing: 0;
        }
        .site-footer .meta a:hover { color: var(--primary-dark); }
        .site-footer .dot { color: var(--ink-mute); margin: 0 0.55rem; }
    </style>
    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

</head>
<body>

    
    <nav class="nav" data-testid="site-nav">
        <div class="nav-inner">
            <a href="/" class="nav-brand">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($logoUrl): ?>
                    <img src="<?php echo e($logoUrl); ?>" alt="<?php echo e($tenant?->name); ?>" data-testid="logo">
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <span><?php echo e($tenant?->name); ?></span>
            </a>

            <div class="nav-links">
                <a href="<?php echo e(route($navBrowseRoute)); ?>"><?php echo e($navInventoryLabel); ?></a>
                <a href="/#about">About</a>
                <a href="/#reviews">Reviews</a>
                <a href="/#contact">Contact</a>
            </div>

            <a href="<?php echo e(route($navBrowseRoute)); ?>" class="nav-cta">
                <?php echo e($navBrowseLabel); ?>

                <span class="dot"><?php echo $pillArrow; ?></span>
            </a>
        </div>
    </nav>

    <main>
        <?php echo e($slot); ?>

    </main>

    
    <footer class="site-footer" data-testid="footer">
        <div class="wrap">
            <p class="brand-line"><?php echo e($tenant?->name); ?></p>
            <p class="meta">
                © <?php echo e(date('Y')); ?> <?php echo e($tenant?->name); ?>

                <span class="dot">·</span>
                Powered by <a href="https://keverna.com" target="_blank" rel="noopener">Keverna</a>
            </p>
        </div>
    </footer>

    
    <?php echo $__env->make('partials.notify-modal', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    
    <?php echo $__env->make('partials._wire-spinner', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo $__env->make('partials._confirm-modal', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>

</body>
</html>
<?php /**PATH /Users/bigtechbro/Desktop/Dev/keverna-solo/resources/views/layouts/tenant-public.blade.php ENDPATH**/ ?>