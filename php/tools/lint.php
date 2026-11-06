<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [$root . '/router.php', $root . '/config/config.example.php'];

foreach (['src', 'public', 'tests', 'tools', 'examples'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

$failures = 0;
$banned = [T_COALESCE, T_COALESCE_EQUAL, T_NULLSAFE_OBJECT_OPERATOR, T_FN];
$conditions = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH, T_CATCH];
$blocks = [T_ELSE, T_DO, T_TRY, T_FINALLY];

foreach ($files as $filename) {
    try {
        $tokens = token_get_all(file_get_contents($filename), TOKEN_PARSE);
        $significant = [];

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], $banned, true)) {
                    throw new RuntimeException('Use explicit control flow at line ' . $token[2] . '.');
                }

                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
            }

            $significant[] = $token;
        }

        foreach ($significant as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            $next = $index + 1;

            if (in_array($token[0], $conditions, true)) {
                $depth = 0;

                while (true) {
                    $part = $significant[$next];

                    if ($part === '(') {
                        $depth += 1;
                    } elseif ($part === ')') {
                        $depth -= 1;
                    }

                    $next += 1;

                    if ($depth === 0) {
                        break;
                    }
                }
            } elseif (!in_array($token[0], $blocks, true)) {
                continue;
            }

            if ($significant[$next] !== '{') {
                throw new RuntimeException('Control-flow blocks need braces at line ' . $token[2] . '.');
            }
        }
    } catch (Throwable $error) {
        $failures += 1;

        fwrite(STDERR, $filename . ': ' . $error->getMessage() . PHP_EOL);
    }
}

if ($failures > 0) {
    exit(1);
}

echo 'PHP syntax and style: ' . count($files) . ' files checked.' . PHP_EOL;
