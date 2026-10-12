<?php

namespace App\Services;

use Symfony\Component\Finder\Finder;

/**
 * Read-only reader for the Obsidian "Second Brain" vault.
 *
 * The vault is a directory of Markdown files bind-mounted read-only into the
 * container (docker-compose.dev.yml, target /vault). Nothing here ever writes:
 * Omnitrack is a consumer of the second brain, not an editor of it. Keeping the
 * vault out of the database is deliberate -- a note is a file, and duplicating
 * its text into Postgres would only create a second copy to keep in sync.
 *
 * Every public method degrades to an empty result when the mount is absent
 * (production has no vault), mirroring GoogleSheetService's credentials probe.
 */
class ObsidianVaultService
{
    /** Absolute path of the vault root, as configured. */
    public function path(): string
    {
        return (string) config('services.obsidian.vault_path', '/vault');
    }

    /** True when the vault root is a readable directory right now. */
    public function available(): bool
    {
        $path = $this->path();

        return $path !== '' && is_dir($path) && is_readable($path);
    }

    /**
     * Full note index: the list the browser and the agents both start from.
     *
     * @return array{available: bool, path: string, folders: list<array{name: string, count: int}>, tags: list<array{name: string, count: int}>, stats: array{notes: int, folders: int, tags: int, words: int, modified_at: ?string}}
     */
    public function index(): array
    {
        $notes = $this->notes();

        $folderCounts = [];
        $tagCounts = [];
        $words = 0;
        $latest = null;

        foreach ($notes as $note) {
            $folder = $note['folder'];
            $folderCounts[$folder] = ($folderCounts[$folder] ?? 0) + 1;

            foreach ($note['tags'] as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }

            // Words come from the byte size rather than a second full read: the
            // index is already the most expensive call and an exact count is not
            // worth doubling its I/O. note() reports the exact figure.
            $words += (int) round($note['size'] / 6);

            if ($latest === null || $note['modified'] > $latest) {
                $latest = $note['modified'];
            }
        }

        ksort($folderCounts);
        arsort($tagCounts);

        return [
            'available' => $this->available(),
            'path' => $this->path(),
            'name' => (string) config('services.obsidian.vault_name', 'Second Brain'),
            'folders' => $this->asCountList($folderCounts),
            'tags' => $this->asCountList($tagCounts),
            'stats' => [
                'notes' => count($notes),
                'folders' => count($folderCounts),
                'tags' => count($tagCounts),
                'words' => $words,
                'modified_at' => $latest,
            ],
        ];
    }

    /**
     * Note headers, newest first, optionally filtered by folder or file name.
     *
     * @return list<array{path: string, title: string, folder: string, size: int, modified: string, tags: list<string>}>
     */
    public function notes(?string $folder = null, ?string $query = null, int $limit = 200): array
    {
        $notes = $this->scan();

        if (filled($folder)) {
            $wanted = trim(str_replace('\\', '/', $folder), '/');
            $notes = array_values(array_filter(
                $notes,
                fn (array $note) => strcasecmp($note['folder'], $wanted) === 0,
            ));
        }

        if (filled($query)) {
            $needle = mb_strtolower(trim((string) $query));
            $notes = array_values(array_filter(
                $notes,
                fn (array $note) => str_contains(mb_strtolower($note['title']), $needle)
                    || str_contains(mb_strtolower($note['path']), $needle),
            ));
        }

        return array_slice($notes, 0, max(1, $limit));
    }

    /**
     * Full-text search across note bodies.
     *
     * A plain substring scan, not a real index: a personal vault is small
     * enough that the read cost is invisible next to the value of having no
     * second index to invalidate. The cap keeps a pathological vault bounded.
     *
     * @return array{query: string, matched: int, results: list<array<string, mixed>>}
     */
    public function search(string $query, int $limit = 50): array
    {
        $needle = trim($query);

        if ($needle === '') {
            return ['query' => '', 'matched' => 0, 'results' => []];
        }

        $limit = max(1, $limit);
        $results = [];
        $matched = 0;

        foreach ($this->scan() as $note) {
            $absolute = $this->resolve($note['path']);

            if ($absolute === null) {
                continue;
            }

            $body = @file_get_contents($absolute);

            if ($body === false) {
                continue;
            }

            $offset = mb_stripos($body, $needle);

            if ($offset === false) {
                continue;
            }

            $matched++;

            if (count($results) >= $limit) {
                // Keep counting matches so the caller can say "50 of 63 shown".
                continue;
            }

            $note['snippet'] = $this->snippet($body, $offset, mb_strlen($needle));
            $note['matches'] = substr_count(mb_strtolower($body), mb_strtolower($needle));
            $results[] = $note;
        }

        return ['query' => $needle, 'matched' => $matched, 'results' => $results];
    }

    /**
     * One note, with its frontmatter, outgoing wikilinks and backlinks.
     *
     * @return array<string, mixed>|null Null when the path is missing, is not a
     *                                   Markdown file, or escapes the vault.
     */
    public function note(string $relative): ?array
    {
        $absolute = $this->resolve($relative);

        if ($absolute === null) {
            return null;
        }

        $raw = @file_get_contents($absolute);

        if ($raw === false) {
            return null;
        }

        [$meta, $body] = $this->splitFrontmatter($raw);
        $slug = pathinfo($absolute, PATHINFO_FILENAME);

        return [
            'path' => $this->relativePath($absolute),
            'title' => $this->title($meta, $body, $slug),
            'folder' => $this->folderOf($this->relativePath($absolute)),
            'content' => $body,
            'frontmatter' => $meta,
            'tags' => $this->tags($meta, $body),
            'links' => $this->links($body),
            'backlinks' => $this->backlinks($slug, $this->relativePath($absolute)),
            'size' => strlen($raw),
            'words' => str_word_count($body) ?: count(preg_split('/\s+/u', trim($body)) ?: []),
            'modified' => date(DATE_ATOM, (int) filemtime($absolute)),
        ];
    }

    /**
     * Resolve a vault-relative path to an absolute one, or null.
     *
     * The guard is the point of this method: a path arriving from the browser
     * or an agent is untrusted, so `..`, absolute paths, non-Markdown files and
     * symlinks pointing outside the vault are all rejected. realpath() is what
     * makes the containment check meaningful -- it resolves symlinks before the
     * prefix comparison, not after.
     */
    public function resolve(string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));

        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        if (! $this->available() || str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:#', $relative) === 1) {
            return null;
        }

        // Reject hidden and excluded segments up front, so a dot directory
        // (.obsidian, .trash) is not a note on this route either -- the scanner
        // skips it, and the reader must agree with the scanner.
        $excluded = array_merge(['node_modules', 'vendor'], (array) config('services.obsidian.excluded_dirs', []));

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return null;
            }

            if (in_array($segment, $excluded, true)) {
                return null;
            }
        }

        $root = realpath($this->path());
        $absolute = realpath(rtrim($this->path(), '/\\').'/'.ltrim($relative, '/'));

        if ($root === false || $absolute === false || ! is_file($absolute)) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $absolute = str_replace('\\', '/', $absolute);

        if (! str_starts_with($absolute, $root.'/')) {
            return null;
        }

        return strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'md' ? $absolute : null;
    }

    /* ------------------------------------------------------------------ scan */

    /**
     * Every note header in the vault, newest first.
     *
     * Only the first few kilobytes of each file are read: frontmatter and the
     * first heading both live at the top, so the index never pays for the whole
     * body of a 100 kB note.
     *
     * @return list<array{path: string, title: string, folder: string, size: int, modified: string, tags: list<string>}>
     */
    private function scan(): array
    {
        if (! $this->available()) {
            return [];
        }

        $root = $this->path();
        $max = max(1, (int) config('services.obsidian.max_notes', 5000));

        $finder = (new Finder())
            ->files()
            ->name('*.md')
            ->in($root)
            ->exclude((array) config('services.obsidian.excluded_dirs', []))
            ->ignoreVCS(true)
            // Dot directories are how Obsidian stores its own state (.obsidian,
            // .trash); they are never notes.
            ->ignoreDotFiles(true);

        $notes = [];

        foreach ($finder as $file) {
            if (count($notes) >= $max) {
                break;
            }

            $head = $this->readHead($file->getPathname());
            [$meta] = $this->splitFrontmatter($head);
            $relative = $this->relativePath($file->getPathname());

            $notes[] = [
                'path' => $relative,
                'title' => $this->title($meta, $head, $file->getFilenameWithoutExtension()),
                'folder' => $this->folderOf($relative),
                'size' => $file->getSize(),
                'modified' => date(DATE_ATOM, $file->getMTime()),
                'tags' => $this->tags($meta, $head),
            ];
        }

        usort($notes, fn (array $a, array $b) => strcmp($b['modified'], $a['modified']));

        return $notes;
    }

    /** Notes that link to $slug, capped so one request cannot read the world. */
    private function backlinks(string $slug, string $selfPath): array
    {
        // A wikilink may be bare ([[Omnitrack]]), path-qualified
        // ([[Projects/Omnitrack]]), aliased ([[Omnitrack|OT]]) or heading-scoped
        // ([[Omnitrack#Roadmap]]), so only the final segment is compared.
        $pattern = '/\[\[(?:[^\]\|#]*\/)?'.preg_quote($slug, '/').'(?:[#\|][^\]]*)?\]\]/i';
        $found = [];
        $scanned = 0;
        $max = max(1, (int) config('services.obsidian.backlink_scan_limit', 400));

        foreach ($this->scan() as $note) {
            if ($scanned++ >= $max) {
                break;
            }

            if ($note['path'] === $selfPath) {
                continue;
            }

            $absolute = $this->resolve($note['path']);

            if ($absolute === null) {
                continue;
            }

            $body = @file_get_contents($absolute);

            if ($body !== false && preg_match($pattern, $body) === 1) {
                $found[] = $note['path'];
            }
        }

        return $found;
    }

    /* ------------------------------------------------------------- markdown */

    /**
     * Split a leading YAML frontmatter block from the body.
     *
     * A deliberately small parser: scalars, inline lists and dash lists cover
     * everything Obsidian's own properties panel writes. It is not a YAML
     * library on purpose -- nested structures would mean a dependency for a
     * feature that only needs title and tags.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function splitFrontmatter(string $raw): array
    {
        if (! str_starts_with(ltrim($raw), '---')) {
            return [[], $raw];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        // Skip any blank lines before the opening fence.
        $start = null;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $start = trim($line) === '---' ? $index : null;
            break;
        }

        if ($start === null) {
            return [[], $raw];
        }

        $meta = [];
        $key = null;
        $end = null;

        for ($i = $start + 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (trim($line) === '---') {
                $end = $i;
                break;
            }

            if (preg_match('/^([A-Za-z0-9_.-]+):\s*(.*)$/', $line, $m) === 1) {
                $key = $m[1];
                $value = trim($m[2]);
                $meta[$key] = $value === '' ? [] : $this->castScalar($value);
                continue;
            }

            if ($key !== null && preg_match('/^\s*-\s+(.+)$/', $line, $m) === 1) {
                $meta[$key] = array_merge((array) $meta[$key], [$this->castScalar(trim($m[1]))]);
            }
        }

        if ($end === null) {
            return [[], $raw];
        }

        return [$meta, implode("\n", array_slice($lines, $end + 1))];
    }

    /** @return array<int, string>|string */
    private function castScalar(string $value): array|string
    {
        $value = trim($value, " \t\"'");

        // [a, b] and a, b are both written by Obsidian for list properties.
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            return $this->splitList(substr($value, 1, -1));
        }

        return $value;
    }

    /** @return list<string> */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $item) => trim($item, " \t\"'"),
            explode(',', $value),
        ), fn (string $item) => $item !== ''));
    }

    /**
     * Tags from frontmatter plus inline #tags in the body.
     *
     * Inline tags are matched textually, so a #tag inside a fenced code block is
     * counted too. Stripping code blocks first would cost a second pass over the
     * body for a cosmetic inaccuracy, which is not worth it.
     *
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private function tags(array $meta, string $body): array
    {
        $tags = [];

        foreach (['tags', 'tag'] as $key) {
            if (! isset($meta[$key])) {
                continue;
            }

            $value = $meta[$key];
            $tags = array_merge($tags, is_array($value) ? $value : $this->splitList((string) $value));
        }

        if (preg_match_all('/(?<![\w\/#])#([A-Za-z0-9][A-Za-z0-9_\/-]*)/u', $body, $matches) > 0) {
            // A leading # is a Markdown heading, not a tag: skip "# " forms.
            $tags = array_merge($tags, $matches[1]);
        }

        $tags = array_values(array_unique(array_filter(array_map(
            fn ($tag) => ltrim(trim((string) $tag), '#'),
            $tags,
        ), fn (string $tag) => $tag !== '' && ! ctype_digit($tag))));

        sort($tags);

        return $tags;
    }

    /** @param array<string, mixed> $meta */
    private function title(array $meta, string $body, string $fallback): string
    {
        if (filled($meta['title'] ?? null) && is_string($meta['title'])) {
            return trim($meta['title']);
        }

        if (preg_match('/^\s{0,3}#\s+(.+)$/m', $body, $m) === 1) {
            return trim($m[1]);
        }

        return $fallback;
    }

    /** @return list<string> Wikilink targets, without aliases or headings. */
    private function links(string $body): array
    {
        if (preg_match_all('/\[\[([^\]\|#]+)(?:#[^\]\|]+)?(?:\|[^\]]+)?\]\]/u', $body, $matches) === 0) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $target) => trim($target),
            $matches[1],
        ), fn (string $target) => $target !== '')));
    }

    private function snippet(string $body, int $offset, int $length): string
    {
        $window = max(80, (int) config('services.obsidian.snippet_chars', 240));
        $start = max(0, $offset - (int) ($window / 2));
        $excerpt = mb_substr($body, $start, $window + $length);

        // Collapse newlines so a snippet is one line in a table cell.
        return trim(preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt);
    }

    /* ---------------------------------------------------------------- paths */

    private function readHead(string $absolute): string
    {
        $handle = @fopen($absolute, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = (string) fread($handle, max(1024, (int) config('services.obsidian.head_bytes', 4096)));
        fclose($handle);

        return $head;
    }


    private function relativePath(string $absolute): string
    {
        $root = rtrim(str_replace('\\', '/', $this->path()), '/');
        $path = str_replace('\\', '/', $absolute);

        return str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
    }

    private function folderOf(string $relative): string
    {
        $folder = str_replace('\\', '/', dirname($relative));

        return $folder === '.' ? '' : trim($folder, '/');
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{name: string, count: int}>
     */
    private function asCountList(array $counts): array
    {
        $list = [];

        foreach ($counts as $name => $count) {
            $list[] = ['name' => (string) $name, 'count' => (int) $count];
        }

        return $list;
    }
}
