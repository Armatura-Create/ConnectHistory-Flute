<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Парность директив Blade.
 *
 * Незакрытый @if или лишний @endforeach не ловится ничем: тесты шаблоны не
 * рендерят, PHPStan их не видит, а Blade падает уже в браузере — на странице,
 * которую после правки могли и не открыть. Проверка дешёвая, поэтому пусть
 * стоит постоянно.
 */
final class BladeSyntaxTest extends TestCase
{
    /** Директивы, у которых обязана быть закрывающая пара. */
    private const PAIRS = [
        'if' => ['endif'],
        'foreach' => ['endforeach'],
        'forelse' => ['endforelse'],
        'for' => ['endfor'],
        'while' => ['endwhile'],
        'php' => ['endphp'],
        'push' => ['endpush'],
        'section' => ['endsection'],
        'once' => ['endonce'],
    ];

    /** @return array<int, string> */
    private static function views(): array
    {
        $root = dirname(__DIR__) . '/Resources/views';
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testViewsExist(): void
    {
        self::assertNotEmpty(self::views(), 'шаблоны не найдены — проверьте путь');
    }

    public function testEveryBlockDirectiveIsClosed(): void
    {
        foreach (self::views() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);

            foreach (self::PAIRS as $open => $closers) {
                // Лукахед (?![a-z]) отделяет @for от @foreach и @forelse,
                // а @if от @endif: в обоих случаях следом идёт буква.
                //
                // Исключение только у @php: форма @php($x = 1) однострочная
                // и закрытия не требует. Ко всем директивам это правило
                // применять нельзя — @if ( тогда перестаёт считаться.
                $openCount = $open === 'php'
                    ? preg_match_all('/@php(?![a-z(])/i', $source)
                    : preg_match_all('/@' . $open . '(?![a-z])/i', $source);

                $closeCount = 0;

                foreach ($closers as $closer) {
                    $closeCount += preg_match_all('/@' . $closer . '(?![a-z])/i', $source);
                }

                self::assertSame(
                    $openCount,
                    $closeCount,
                    "{$name}: @{$open} встречается {$openCount} раз, закрытий — {$closeCount}"
                );
            }
        }
    }

    /**
     * @forelse закрывается @endforelse, но между ними обязателен @empty —
     * без него Blade падает на разборе.
     */
    public function testForelseAlwaysHasEmpty(): void
    {
        foreach (self::views() as $file) {
            $source = (string) file_get_contents($file);

            $forelse = preg_match_all('/@forelse(?![a-z])/i', $source);

            if ($forelse === 0) {
                continue;
            }

            self::assertGreaterThanOrEqual(
                $forelse,
                preg_match_all('/@empty(?![a-z])/i', $source),
                basename($file) . ': у @forelse должен быть @empty'
            );
        }
    }

    /**
     * Значения из базы выводятся только через {{ }} — экранирование обязательно.
     * {!! !!} допустим лишь для того, что мы формируем сами (SVG иконки, готовый
     * HTML графика), поэтому его наличие проверяется глазами, а список — тестом.
     */
    public function testRawEchoIsUsedOnlyWhereExpected(): void
    {
        $allowed = [
            // SVG иконки из IconFinder — сформированы панелью, не пользователем
            'mod-settings.blade.php',
            'settings.blade.php',
            'profile.blade.php',
        ];

        foreach (self::views() as $file) {
            $name = basename($file);
            $raw = preg_match_all('/\{!!/', (string) file_get_contents($file));

            if ($raw === 0) {
                continue;
            }

            self::assertContains(
                $name,
                $allowed,
                "{$name}: неэкранированный вывод — данные из базы обязаны идти через {{ }}"
            );
        }
    }
}
