<?php

declare(strict_types=1);

namespace Habi\Tests\TestSupport;

use Habi\Support\TranslationExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The storefront Twig environment as public/index.php builds it: the views
 * directory, HTML autoescaping and the translation functions (M14).
 */
final class Views
{
    public static function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(habi_repo_root() . '/views'), ['autoescape' => 'html']);
        $twig->addExtension(new TranslationExtension());

        return $twig;
    }
}
