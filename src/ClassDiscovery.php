<?php

namespace Schildhain\ClassDiscovery;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use ReflectionClass;
use Illuminate\Support\Arr;

/**
 * ClassDiscovery
 * --------------
 * A builder‑style utility to discover PHP classes within a Laravel (or generic
 * PSR‑4) project.
 *
 * The component keeps a minimal PSR‑4 cache that maps the *shallowest* base
 * directory to its root namespace (e.g. `/app/Sub/Deep` → `\App\`). Every
 * class beneath that directory is then resolved by simple interpolation. If
 * no mapping matches a file path, a lightweight regular‑expression fallback
 * extracts the namespace from the file header (assuming the *filename equals
 * the class name*) and the derived root mapping is cached for future calls.
 */
class ClassDiscovery
{
    protected $app;

    /**
     * Normalised (trailing‑slash) base‑path ⇒ base‑namespace map.
     *
     * @var array<string,string>
     */
    protected static array $namespaces = [];

    /**
     * Symfony Finder used for file enumeration.
     */
    protected Finder $finder;

    /**
     * Pipeline of user‑defined ReflectionClass filters.
     *
     * @var array<callable(ReflectionClass):bool>
     */
    protected array $filters = [];

    /**
     * Create a new ClassDiscovery instance.
     *
     * @param Application $app    Laravel service container.
     * @param Finder|null $finder Optional Finder; if omitted an instance is
     *                            resolved from the container.
     */
    public function __construct(Application $app, ?Finder $finder = null)
    {
        $this->finder = $finder ?: $app->make(Finder::class);
        $this->finder->files()->name('*.php');
    }

    /**
     * Return the internal Finder instance so callers can tweak it directly if
     * required (e.g. add exclusions).
     *
     * @return Finder
     */
    public function getFinder(): Finder
    {
        return $this->finder;
    }

    /**
     * Expose the static namespace cache (mainly for debugging/unit tests).
     *
     * @return array<string,string>
     */
    public static function getNamespaces(): array
    {
        return self::$namespaces;
    }

    /**
     * Register a base‑path ⇒ base‑namespace mapping.
     *
     * The supplied path/namespace may itself be deep (e.g. `/app/Sub/Foo`
     * paired with `App\\Sub\\Foo`). The method collapses the mapping to its
     * *root* so interpolation is sufficient for all descendants.
     *
     * @param string $basePath      Absolute directory in the filesystem.
     * @param string $baseNamespace Corresponding namespace (with or without
     *                              leading/trailing backslashes).
     * @return void
     */
    public static function addNamespace(string $basePath, string $baseNamespace): void
    {
        $basePath = self::normalisePath($basePath);

        $baseNamespace = '\\' . trim($baseNamespace, '\\') . '\\';

        self::$namespaces[$basePath] = $baseNamespace;
        uksort(self::$namespaces, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /**
     * Add one or more directories to be scanned during discovery.
     *
     * Examples:
     * ```php
     * $d->in('/path');
     * $d->in('/path', 'Vendor\\Package');
     * $d->in(['/a', '/b']);
     * $d->in(['/pkg' => 'Vendor\\Pkg']);
     * ```
     *
     * @param string|array<string>|array<string,string> $path Directories or
     *                                                        map of directory⇒namespace.
     * @param string|null                               $namespace Optional
     *                                                        namespace when
     *                                                        using string $path.
     * @return self
     */
    public function in(array|string $path, ?string $namespace = null): self
    {
        $items = [];
        if (Arr::accessible($path)) {
            foreach ($path as $k => $v) {
                if(is_int($k)) {
                    [$k, $v] = [$v, $namespace];
                }
                $items[$k] = is_int($k) ? $namespace : $v;
            }
        } else {
            $items[$path] = $namespace;
        }

        foreach ($items as $dir => $ns) {
            $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
            if(!is_dir($dir)) {
                continue;
            }
            $this->finder->in($dir);
            if ($ns) {
                self::addNamespace($dir, $ns);
            }
        }

        return $this;
    }

    /**
     * Toggle recursive directory scanning.
     *
     * @param bool $recursive Set to *true* to search nested directories, *false*
     *                        to limit depth to the provided paths only.
     * @return self
     */
    public function recursive(bool $recursive = true): self
    {
        $this->finder->depth($recursive ? '>= 0' : '== 0');
        return $this;
    }

    /**
     * Append a custom filter callable. The callable receives a ReflectionClass
     * and must return *true* to keep the class or *false* to discard it.
     *
     * @param callable(ReflectionClass):bool $closure
     * @return self
     */
    public function filter(callable $closure, string $filterId = null, bool $singular = false): self
    {
        if($filterId === null) {
            $filterId = Str::uuid()->toString();
        }

        if(!isset($this->filters[$filterId]) || $singular) {
            $this->filters[$filterId] = [];
        }

        $this->filters[$filterId][] = $closure;
        return $this;
    }

    /**
     * Remove all filters registered under the given ID.
     *
     * @param string $filterId Identifier used when registering the filter.
     * @return self
     */
    public function removeFilter(string $filterId): self
    {
        unset($this->filters[$filterId]);
        return $this;
    }

    /**
     * Restrict to subclasses of one OR more parent classes.
     *
     * @param class‑string|array<class‑string> $classes Parent class or list thereof.
     * @return $this
     */
    public function subclassOf(string|array $classes): self
    {
        $need = (array) $classes;
        return $this->filter(fn(ReflectionClass $r): bool => array_reduce(
            $need,
            fn(bool $carry, string $cls): bool => $carry || $r->isSubclassOf($cls),
            false
        ), 'subclassOf');
    }

    /**
     * Restrict to classes that implement one OR more methods.
     *
     * @param string|array $methods Method name or list thereof.
     * @return $this
     */
    public function hasMethod(string|array $methods, bool $isStatic = false): self
    {
        $need = (array) $methods;
        return $this->filter(fn(ReflectionClass $r): bool => array_reduce(
            $need,
            fn(bool $carry, string $method): bool => $carry || ($r->hasMethod($method) && $r->getMethod($method)->isStatic() === $isStatic),
            false
        ), 'hasMethod');
    }

    /**
     * Restrict to classes that implement one OR more static methods.
     *
     * @param string|array $methods Method name or list thereof.
     * @return $this
     */
    public function hasStaticMethod(string|array $methods): self
    {
        return $this->hasMethod($methods, true);
    }

    /**
     * Require abstract classes.
     * @return $this
     */
    public function isType(string $type): self
    {
        $filter = match(strtolower($type)) {
            'class', 'classes' => fn(ReflectionClass $r): bool => !$r->isInterface() && !$r->isTrait() && !$r->isEnum(),
            'interface', 'interfaces' => fn(ReflectionClass $r): bool => $r->isInterface(),
            'trait', 'traits' => fn(ReflectionClass $r): bool => $r->isTrait(),
            'enum', 'enums' => fn(ReflectionClass $r): bool => $r->isEnum(),
            default => false
        };
        return $this->filter($filter, 'type');
    }


    /**
     * Require abstract classes.
     * @return $this
     */
    public function isEnum(): self
    {
        return $this->isType('enum');
    }

    /**
     * Require abstract classes.
     * @return $this
     */
    public function isInterface(): self
    {
        return $this->isType('interface');
    }

    /**
     * Require abstract classes.
     * @return $this
     */
    public function isTrait(): self
    {
        return $this->isType('trait');
    }

    /**
     * Require abstract classes.
     * @return $this
     */
    public function isClass(): self
    {
        return $this->isType('class');
    }

    /**
     * Require abstract classes.
     * @return $this
     */
    public function abstract(bool $abstract = true): self
    {
        return $this->filter(fn(ReflectionClass $r): bool => $r->isAbstract() === $abstract, 'abstract', true);
    }

    /**
     * Require abstract classes.
     * @return $this
     */
    public function final(bool $final = true): self
    {
        return $this->filter(fn(ReflectionClass $r): bool => $r->isFinal() === $final, 'final', true);
    }

    /**
     * Restrict to classes implementing **any** of the given interfaces.
     *
     * @param class‑string|array<class‑string> $interfaces Interface or list thereof.
     * @return $this
     */
    public function implements(string|array $interfaces): self
    {
        $need = (array) $interfaces;
        return $this->filter(fn(ReflectionClass $r): bool => !empty(array_intersect($need, $r->getInterfaceNames())), 'implements');
    }


    /**
     * Restrict to classes using **any** of the given traits (directly).
     *
     * @param class‑string|array<class‑string> $traits Trait or list thereof.
     * @return $this
     */
    public function uses(string|array $traits): self
    {
        $need = (array) $traits;
        return $this->filter(fn(ReflectionClass $r): bool => !empty(array_intersect($need, $r->getTraitNames())), 'uses');
    }

    /**
     * Scan the configured paths and return fully‑qualified class names that
     * satisfy every registered filter.
     *
     * @return array<int,class-string>
     */
    public function discover(null|string|array $paths = null): array
    {
        if(!empty($paths)) {
            $this->in($paths);
        }

        $classes = [];

        if(!isset($this->filters['type'])) {
            $this->isType('class');
        }

        foreach ($this->finder as $file) {
            $path = $file->getRealPath();
            if (!$path) {
                continue;
            }

            $fqcn = self::resolveClassFromPath($path);
            if (!$fqcn || !class_exists($fqcn)) {
                continue;
            }

            $ref = new ReflectionClass($fqcn);
            if (!$this->passesFilters($ref)) {
                continue;
            }

            $classes[] = $fqcn;
        }

        return $classes;
    }


    /**
     * Alias of `discover()`.
     *
     * @return array<int,class-string>
     */
    public function get()
    {
        return $this->discover();
    }

    /**
     * Determine whether a ReflectionClass instance passes *all* configured
     * filters.
     *
     * @param ReflectionClass $ref Reflection of the candidate class.
     * @return bool True if the class should be included.
     */
    protected function passesFilters(ReflectionClass $ref): bool
    {
        foreach ($this->filters as $id => $filter) {
            $any = false;
            $all = true;
            foreach ($filter as $closure) {
                $passes = $closure($ref);
                $any = $any || $passes;
                $all = $all && $passes;
            }

            if(!$any) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert a path to the current OS directory separator, apply `realpath()`
     * when possible and ensure a trailing separator.
     *
     * @param string $path Raw path.
     * @return string Normalised absolute (or relative) path.
     */
    protected static function normalisePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        return rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Resolve the fully‑qualified class name for a given PHP file.
     *
     * 1. Attempt fast PSR‑4 interpolation using the cached mappings.
     * 2. Fallback to regex extraction of the namespace (*filename = classname*),
     *    cache the derived root mapping, and return the class.
     *
     * @param string $filePath Absolute path to the PHP source file.
     * @return class-string|null Fully‑qualified class or *null* if undetermined.
     */
    protected static function resolveClassFromPath(string $filePath): ?string
    {
        $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

        $sorted = self::$namespaces;
        uksort($sorted, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($sorted as $basePath => $baseNs) {
            if (str_starts_with($filePath, $basePath)) {
                $relative = substr($filePath, strlen($basePath), -4); // drop base & .php
                $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
                return $baseNs . $relative;
            }
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            return null;
        }

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $code, $m)) {
            $namespace = trim($m[1]);
        }

        $class = pathinfo($filePath, PATHINFO_FILENAME);
        if ($class === '') {
            return null;
        }

        $fqcn = '\\' . trim($namespace . '\\' . $class, '\\');

        $dir         = dirname($filePath) . DIRECTORY_SEPARATOR;
        $nsParts     = explode('\\', trim($namespace, '\\'));
        $extraDepth  = max(count($nsParts) - 1, 0);
        $segments    = explode(DIRECTORY_SEPARATOR, rtrim($dir, DIRECTORY_SEPARATOR));
        $basePath    = implode(DIRECTORY_SEPARATOR, array_slice($segments, 0, -$extraDepth)) . DIRECTORY_SEPARATOR;
        $rootNs      = '\\' . ($nsParts[0] ?? '') . '\\';

        if ($basePath && $rootNs && !isset(self::$namespaces[$basePath])) {
            self::$namespaces[$basePath] = $rootNs;
        }

        return $fqcn;
    }
}
