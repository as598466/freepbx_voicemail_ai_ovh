<?php

declare(strict_types=1);

namespace VoicemailAi\Mail;

use RuntimeException;
use Throwable;

/**
 * Renders plain PHP templates. Templates receive their variables plus an "$e" HTML escaping helper.
 */
final readonly class TemplateRenderer
{
    /**
     * @param array<string, mixed> $variables
     */
    public function render(string $template, array $variables): string
    {
        if (!is_file($template) || !is_readable($template)) {
            throw new RuntimeException(sprintf('Template "%s" is not readable.', $template));
        }

        $variables['e'] = static fn(mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );

        $render = static function (string $__template, array $__variables): string {
            extract($__variables, EXTR_SKIP);
            ob_start();

            try {
                require $__template;
            } catch (Throwable $exception) {
                ob_end_clean();

                throw $exception;
            }

            return (string) ob_get_clean();
        };

        return $render($template, $variables);
    }
}
