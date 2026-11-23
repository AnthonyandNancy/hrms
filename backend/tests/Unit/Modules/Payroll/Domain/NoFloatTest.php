<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A source-level guard against accidental introduction of binary floating
 * point arithmetic into payroll or the shared money primitives. The check is
 * token based so words in comments do not create false positives, while a
 * float literal, float type declaration, float cast, or floatval call does.
 */
final class NoFloatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenSnippets(): iterable
    {
        yield 'float type' => ['<?php function f(float $value): void {}', 'float'];
        yield 'float literal' => ['<?php $value = 1.5;', '1.5'];
        yield 'float cast' => ['<?php $value = (float) $other;', '(float)'];
        yield 'floatval call' => ['<?php $value = floatval($other);', 'floatval'];
        yield 'round call' => ['<?php $value = round($other);', 'round'];
        yield 'native abs call' => ['<?php $value = abs($other);', 'abs'];
    }

    #[DataProvider('forbiddenSnippets')]
    public function test_scanner_detects_each_forbidden_float_pattern(string $source, string $expectedToken): void
    {
        $violations = $this->scan($source);

        $this->assertNotSame([], $violations);
        $this->assertStringContainsString($expectedToken, implode('
', $violations));
    }

    public function test_scanner_allows_integer_arithmetic_and_comments_about_floating_point(): void
    {
        $this->assertSame(
            [],
            $this->scan('<?php // never use floating point here
$total = $a + $b;'),
        );
    }

    public function test_payroll_and_money_sources_contain_no_float_arithmetic(): void
    {
        $directories = [
            dirname(__DIR__, 5).'/app/Modules/Payroll',
            dirname(__DIR__, 5).'/app/Shared/Money',
        ];
        $violations = [];

        foreach ($directories as $directory) {
            $this->assertDirectoryExists($directory);

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $this->assertIsString($contents);

                foreach ($this->scan($contents) as $violation) {
                    $violations[] = $file->getPathname().':'.$violation;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Binary floating point is forbidden in payroll and money domain code:\n".implode("\n", $violations),
        );
    }

    /**
     * @return list<string>
     */
    private function scan(string $source): array
    {
        $tokens = token_get_all($source);
        $violations = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;
            $normalized = strtolower($text);

            $isFloatToken = $id === T_DNUMBER;
            $isFloatCast = $id === T_DOUBLE_CAST;
            $isFloatType = in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && in_array($normalized, ['float', 'double', 'floatval', 'doubleval', 'is_float', 'is_double', 'abs'], true);
            $isNativeRound = $id === T_STRING
                && $normalized === 'round'
                && ! $this->isMethodOrDeclarationCall($tokens, $index);

            if ($isFloatToken || $isFloatCast || $isFloatType || $isNativeRound) {
                $violations[] = $line.': '.$text;
            }
        }

        return $violations;
    }

    /**
     * Method calls and method declarations named round() are exact Decimal
     * operations, not PHP's native float round(). A bare round() call is
     * forbidden.
     *
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function isMethodOrDeclarationCall(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $previous = $tokens[$cursor];
            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_string($previous)
                ? $previous === '>' || $previous === ':'
                : in_array($previous[0], [T_FUNCTION, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
        }

        return false;
    }
}
