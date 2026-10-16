<?php

namespace App\Support;

use Illuminate\Translation\Translator;

/**
 * The app writes its words as `{n}` placeholders, the same on the server and in the browser's t(). Laravel's own
 * translator only fills `:name`, so a server message like "at least {n} boxes" reached the screen with the braces
 * still in it. This fills both styles.
 */
class BraceTranslator extends Translator
{
    protected function makeReplacements($line, array $replace)
    {
        $line = parent::makeReplacements($line, $replace);

        if ($replace === [] || ! str_contains((string) $line, '{')) {
            return $line;
        }

        $map = [];
        foreach ($replace as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $map['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr((string) $line, $map);
    }
}
