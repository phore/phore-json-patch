<?php

declare(strict_types=1);

use Phore\JsonPatch\{JsonPatch, JsonPatchApplier, JsonValue, PatchApplyOptions, PatchValidationException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
{
    public static function cases(): iterable
    {
        foreach (['tests.json', 'spec_tests.json'] as $file) {
            foreach (JsonValue::decode(file_get_contents(__DIR__ . '/fixtures/json-patch-tests/' . $file)) as $index => $case) {
                if (!property_exists($case, 'doc') || !property_exists($case, 'patch')) {
                    continue; // The upstream format permits comment-only records.
                }
                yield $file . ':' . $index . ' ' . ($case->comment ?? '') => [$case];
            }
        }
    }

    #[DataProvider('cases')]
    public function testUpstreamCase(stdClass $case): void
    {
        if ($case->disabled ?? false) {
            self::markTestSkipped('Disabled upstream; see docs/test-coverage.md.');
        }
        $original = JsonValue::encode($case->doc);
        try {
            try {
                $result = (new JsonPatchApplier())->apply($case->doc, JsonPatch::fromArray($case->patch), new PatchApplyOptions(
                    allowedOperations: ['add', 'remove', 'replace', 'move', 'copy', 'test'],
                    allowRootReplacement: true,
                ));
            } catch (PatchValidationException $exception) {
                self::assertTrue(property_exists($case, 'error'), 'Unexpected error: ' . $exception->errorCode);
                return;
            }
            self::assertFalse(property_exists($case, 'error'), 'Expected rejection: ' . ($case->error ?? ''));
            if (property_exists($case, 'expected')) {
                self::assertTrue($result->documentExists);
                self::assertTrue(JsonValue::equal($case->expected, $result->value), JsonValue::encode($result->value));
            }
        } finally {
            self::assertSame($original, JsonValue::encode($case->doc), 'Input must remain unchanged, including on failure.');
        }
    }
}
