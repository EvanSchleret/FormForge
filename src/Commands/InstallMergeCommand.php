<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallMergeCommand extends Command
{
    protected $signature = 'formforge:install:merge
        {--dry-run : Preview changes without writing files}
        {--skip-migrations : Skip publishing missing migrations}
        {--no-backup : Do not create backup file before rewriting config}';

    protected $description = 'Merge latest FormForge package files into existing installation without overwriting project overrides';

    public function handle(Filesystem $files): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $skipMigrations = (bool) $this->option('skip-migrations');
        $backup = ! (bool) $this->option('no-backup');

        if (! $skipMigrations) {
            if ($dryRun) {
                $this->line('[dry-run] Would publish missing FormForge migrations (tag: formforge-migrations).');
            } else {
                $this->call('vendor:publish', [
                    '--tag' => 'formforge-migrations',
                    '--force' => false,
                ]);
            }
        }

        $configPath = config_path('formforge.php');
        $defaultPath = dirname(__DIR__, 2) . '/config/formforge.php';

        if (! $files->exists($defaultPath)) {
            throw new FormForgeException("Default FormForge config template not found at [{$defaultPath}].");
        }

        if (! $files->exists($configPath)) {
            if ($dryRun) {
                $this->line("[dry-run] Would create config file at [{$configPath}] from latest template.");
            } else {
                $files->ensureDirectoryExists(dirname($configPath));
                $files->copy($defaultPath, $configPath);
                $this->info("Config file created: {$configPath}");
            }

            return self::SUCCESS;
        }

        $defaults = $this->loadConfigArray($defaultPath);
        $existing = $this->loadConfigArray($configPath);
        $overrides = $this->diffOverrides($defaults, $existing);
        $merged = array_replace_recursive($defaults, is_array($overrides) ? $overrides : []);
        $rendered = $this->renderMergedConfig((string) $files->get($defaultPath), is_array($overrides) ? $overrides : []);

        if ($existing === $merged) {
            $this->info('Config is already up to date. No changes needed.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('[dry-run] Config changes detected and would be written.');

            return self::SUCCESS;
        }

        if ($backup) {
            $backupPath = $configPath . '.bak.' . date('Ymd_His');
            $files->copy($configPath, $backupPath);
            $this->line("Backup created: {$backupPath}");
        }

        $files->put($configPath, $rendered);
        $this->info("Config merged: {$configPath}");

        return self::SUCCESS;
    }

    private function loadConfigArray(string $path): array
    {
        $data = (static function (string $path): mixed {
            return require $path;
        })($path);

        return is_array($data) ? $data : [];
    }

    private function diffOverrides(mixed $defaults, mixed $existing): mixed
    {
        if (! is_array($defaults) || ! is_array($existing)) {
            return $defaults === $existing ? null : $existing;
        }

        $diff = [];

        foreach ($existing as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                $diff[$key] = $value;
                continue;
            }

            $entry = $this->diffOverrides($defaults[$key], $value);

            if ($entry !== null) {
                $diff[$key] = $entry;
            }
        }

        return $diff === [] ? null : $diff;
    }

    private function renderMergedConfig(string $defaultTemplateRaw, array $overrides): string
    {
        $template = $this->normalizeTemplate($defaultTemplateRaw);
        $mergedTemplate = $this->applyOverridesInline($template, $overrides);
        $mergedTemplate = preg_replace('/^\s*\$config\s*=\s*\[/', 'return [', $mergedTemplate, 1) ?? $mergedTemplate;

        return <<<PHP
<?php

declare(strict_types=1);

{$mergedTemplate}

PHP;
    }

    private function normalizeTemplate(string $defaultTemplateRaw): string
    {
        $template = str_replace("\r\n", "\n", $defaultTemplateRaw);
        $template = preg_replace('/^\s*<\?php\s*/', '', $template, 1) ?? $template;
        $template = preg_replace('/^\s*declare\(strict_types=1\);\s*/', '', $template, 1) ?? $template;

        $needle = 'return [';
        $position = strpos($template, $needle);

        if ($position === false) {
            throw new FormForgeException('Unable to parse default config template for merge rendering.');
        }

        $template = substr_replace($template, '$config = [', $position, strlen($needle));

        $template = preg_replace('/\]\s*;\s*$/', "];", trim($template)) ?? trim($template);

        return trim($template);
    }

    /**
     * @param array<string|int, mixed> $overrides
     */
    private function applyOverridesInline(string $template, array $overrides, array $path = []): string
    {
        foreach ($overrides as $key => $value) {
            $currentPath = [...$path, $key];
            $tree = $this->parseConfigTemplate($template);
            $existingNode = $this->findNodeByPath($tree, $currentPath);

            if (is_array($value) && $existingNode !== null && ($existingNode['is_array'] ?? false) === true) {
                $template = $this->applyOverridesInline($template, $value, $currentPath);
                continue;
            }

            if ($existingNode !== null) {
                $template = $this->replaceNodeValue($template, $existingNode, $value);
                continue;
            }

            $parentPath = $currentPath;
            array_pop($parentPath);

            $tree = $this->parseConfigTemplate($template);
            $parentNode = $this->findNodeByPath($tree, $parentPath);

            if ($parentNode === null || ($parentNode['is_array'] ?? false) !== true) {
                throw new FormForgeException('Unable to apply config override merge for missing parent path.');
            }

            $template = $this->insertNodeValue($template, $parentNode, $key, $value);
        }

        return $template;
    }

    /**
     * @param array<int, string|int> $path
     */
    private function findNodeByPath(array $root, array $path): ?array
    {
        if ($path === []) {
            return $root;
        }

        $current = $root;

        foreach ($path as $segment) {
            $lookup = $this->nodeLookupKey($segment);
            $children = $current['children'] ?? [];

            if (! array_key_exists($lookup, $children)) {
                return null;
            }

            $current = $children[$lookup];
        }

        return $current;
    }

    private function parseConfigTemplate(string $template): array
    {
        $tokens = $this->tokenizeTemplate($template);
        $index = 0;
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if (($token['id'] ?? null) !== T_VARIABLE || $token['text'] !== '$config') {
                $index++;
                continue;
            }

            $cursor = $this->skipTrivia($tokens, $index + 1);

            if (! isset($tokens[$cursor]) || $tokens[$cursor]['text'] !== '=') {
                $index++;
                continue;
            }

            $cursor = $this->skipTrivia($tokens, $cursor + 1);

            if (! isset($tokens[$cursor]) || $tokens[$cursor]['text'] !== '[') {
                $index++;
                continue;
            }

            return $this->parseArrayNode($tokens, $cursor);
        }

        throw new FormForgeException('Unable to parse config template root array.');
    }

    private function tokenizeTemplate(string $template): array
    {
        $prefix = '<?php ';
        $rawTokens = token_get_all($prefix . $template);
        $offset = -strlen($prefix);
        $tokens = [];

        foreach ($rawTokens as $rawToken) {
            $id = is_array($rawToken) ? $rawToken[0] : null;
            $text = is_array($rawToken) ? $rawToken[1] : $rawToken;
            $start = $offset;
            $offset += strlen($text);
            $end = $offset;

            if ($end <= 0) {
                continue;
            }

            if ($start < 0) {
                $text = substr($text, -$start);
                $start = 0;
            }

            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'start' => $start,
                'end' => $end,
            ];
        }

        return $tokens;
    }

    private function skipTrivia(array $tokens, int $index): int
    {
        $count = count($tokens);

        while ($index < $count) {
            $id = $tokens[$index]['id'] ?? null;
            if (! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }

            $index++;
        }

        return $index;
    }

    private function parseArrayNode(array $tokens, int &$index): array
    {
        if (! isset($tokens[$index]) || $tokens[$index]['text'] !== '[') {
            throw new FormForgeException('Invalid array parse cursor while merging config.');
        }

        $node = [
            'is_array' => true,
            'value_start' => $tokens[$index]['start'],
            'value_end' => $tokens[$index]['end'],
            'open_bracket_start' => $tokens[$index]['start'],
            'close_bracket_start' => $tokens[$index]['start'],
            'children' => [],
        ];

        $index++;
        $autoIndex = 0;

        while (isset($tokens[$index])) {
            $index = $this->skipTrivia($tokens, $index);

            if (! isset($tokens[$index])) {
                break;
            }

            if ($tokens[$index]['text'] === ']') {
                $node['close_bracket_start'] = $tokens[$index]['start'];
                $node['value_end'] = $tokens[$index]['end'];
                $index++;
                break;
            }

            $entryIndex = $index;
            [$isKeyed, $entryKey, $afterArrow] = $this->detectKeyedEntry($tokens, $index);

            if ($isKeyed) {
                $index = $this->skipTrivia($tokens, $afterArrow);
            } else {
                $entryKey = $autoIndex;
                $autoIndex++;
                $index = $entryIndex;
            }

            if (! isset($tokens[$index])) {
                break;
            }

            $valueStart = $tokens[$index]['start'];

            if ($tokens[$index]['text'] === '[') {
                $child = $this->parseArrayNode($tokens, $index);
                $child['value_start'] = $valueStart;
                $child['key'] = $entryKey;
            } else {
                $valueEnd = $this->parseExpressionEnd($tokens, $index);
                $child = [
                    'is_array' => false,
                    'value_start' => $valueStart,
                    'value_end' => $valueEnd,
                    'key' => $entryKey,
                ];
            }

            $node['children'][$this->nodeLookupKey($entryKey)] = $child;

            $index = $this->skipTrivia($tokens, $index);

            if (isset($tokens[$index]) && $tokens[$index]['text'] === ',') {
                $index++;
            }
        }

        return $node;
    }

    private function detectKeyedEntry(array $tokens, int $index): array
    {
        if (! isset($tokens[$index])) {
            return [false, null, $index];
        }

        $token = $tokens[$index];
        $id = $token['id'] ?? null;

        if ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_LNUMBER) {
            return [false, null, $index];
        }

        $key = $id === T_LNUMBER
            ? (int) $token['text']
            : stripcslashes(substr($token['text'], 1, -1));

        $cursor = $this->skipTrivia($tokens, $index + 1);

        if (! isset($tokens[$cursor]) || $tokens[$cursor]['text'] !== '=>') {
            return [false, null, $index];
        }

        return [true, $key, $cursor + 1];
    }

    private function parseExpressionEnd(array $tokens, int &$index): int
    {
        $count = count($tokens);
        $paren = 0;
        $bracket = 0;
        $brace = 0;
        $lastEnd = $tokens[$index]['end'];

        while ($index < $count) {
            $text = $tokens[$index]['text'];

            if ($paren === 0 && $bracket === 0 && $brace === 0 && ($text === ',' || $text === ']')) {
                break;
            }

            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                $paren = max(0, $paren - 1);
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                if ($bracket === 0 && $paren === 0 && $brace === 0) {
                    break;
                }
                $bracket = max(0, $bracket - 1);
            } elseif ($text === '{') {
                $brace++;
            } elseif ($text === '}') {
                $brace = max(0, $brace - 1);
            }

            $lastEnd = $tokens[$index]['end'];
            $index++;
        }

        return $lastEnd;
    }

    private function replaceNodeValue(string $template, array $node, mixed $value): string
    {
        $indent = $this->lineLeadingIndent($template, (int) $node['value_start']);
        $replacement = $this->exportPhpValue($value, $indent);

        return substr_replace(
            $template,
            $replacement,
            (int) $node['value_start'],
            (int) $node['value_end'] - (int) $node['value_start'],
        );
    }

    private function insertNodeValue(string $template, array $parentNode, string|int $key, mixed $value): string
    {
        $open = (int) $parentNode['open_bracket_start'];
        $close = (int) $parentNode['close_bracket_start'];
        $parentIndent = $this->lineLeadingIndent($template, $open);
        $entryIndent = $parentIndent . '    ';
        $keyCode = is_int($key) ? (string) $key : var_export($key, true);
        $valueCode = $this->exportPhpValue($value, $entryIndent);
        $entry = $entryIndent . $keyCode . ' => ' . $valueCode . ",\n";
        $inner = substr($template, $open + 1, $close - $open - 1);

        if (! str_contains($inner, "\n")) {
            $inline = "\n" . $entry . $parentIndent;

            return substr_replace($template, $inline, $open + 1, $close - $open - 1);
        }

        $closeLineStart = strrpos(substr($template, 0, $close), "\n");
        $insertAt = $closeLineStart === false ? $close : $closeLineStart + 1;

        return substr_replace($template, $entry, $insertAt, 0);
    }

    private function lineLeadingIndent(string $content, int $offset): string
    {
        $before = substr($content, 0, $offset);
        $lineStart = strrpos($before, "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $line = substr($content, $lineStart, $offset - $lineStart);
        preg_match('/^\s*/', $line, $matches);

        return $matches[0] ?? '';
    }

    private function exportPhpValue(mixed $value, string $indent): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $nextIndent = $indent . '    ';
        $lines = ['['];

        foreach ($value as $key => $item) {
            $prefix = is_int($key)
                ? $nextIndent . $key . ' => '
                : $nextIndent . var_export((string) $key, true) . ' => ';

            $lines[] = $prefix . $this->exportPhpValue($item, $nextIndent) . ',';
        }

        $lines[] = $indent . ']';

        return implode("\n", $lines);
    }

    private function nodeLookupKey(string|int $key): string
    {
        return is_int($key) ? 'i:' . $key : 's:' . $key;
    }
}
