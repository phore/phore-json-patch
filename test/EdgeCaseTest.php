<?php

declare(strict_types=1);

use Phore\JsonPatch\{JsonPatch, JsonPatchApplier, JsonValue, PatchApplyOptions, PatchValidationException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independently written regressions; source comparison is documented in docs/test-coverage.md. */
final class EdgeCaseTest extends TestCase
{
    public static function successfulCases(): iterable
    {
        foreach (['move', 'copy'] as $op) {
            yield "$op child object to root" => ['{"child":{"x":3},"other":9}', [['op'=>$op,'from'=>'/child','path'=>'']], '{"x":3}'];
            yield "$op child array to root" => ['{"child":[3,4]}', [['op'=>$op,'from'=>'/child','path'=>'']], '[3,4]'];
            yield "$op root to itself" => ['{"x":3}', [['op'=>$op,'from'=>'','path'=>'']], '{"x":3}'];
            yield "$op appends to array" => ['[3,4,5]', [['op'=>$op,'from'=>'/0','path'=>'/-']], $op === 'move' ? '[4,5,3]' : '[3,4,5,3]'];
        }
        yield 'scalar root replacement' => ['"before"', [['op'=>'replace','path'=>'','value'=>false]], 'false'];
        yield 'test entire root' => ['{"x":[3,null]}', [['op'=>'test','path'=>'','value'=>(object)['x'=>[3,null]]]], '{"x":[3,null]}'];
        yield 'copy root into child is finite and detached' => ['{"x":3}', [['op'=>'copy','from'=>'','path'=>'/snapshot'],['op'=>'replace','path'=>'/x','value'=>4]], '{"x":4,"snapshot":{"x":3}}'];
        yield 'move backwards uses post-removal positions' => ['[3,4,5,6]', [['op'=>'move','from'=>'/3','path'=>'/1']], '[3,6,4,5]'];
        yield 'move overwrites existing object member' => ['{"a":{"x":3},"b":9}', [['op'=>'move','from'=>'/a','path'=>'/b']], '{"b":{"x":3}}'];
        yield 'copy nested lists isolates both directions' => ['{"a":[{"x":3}]}', [['op'=>'copy','from'=>'/a','path'=>'/b'],['op'=>'replace','path'=>'/a/0/x','value'=>4],['op'=>'add','path'=>'/b/-','value'=>5]], '{"a":[{"x":4}],"b":[{"x":3},5]}'];
        yield 'sequential removals reindex arrays' => ['[3,4,5,6]', [['op'=>'remove','path'=>'/1'],['op'=>'remove','path'=>'/2']], '[3,5]'];
        yield 'numeric looking object keys stay literal' => ['{"01":1,"1e0":2,"-":3}', [['op'=>'replace','path'=>'/01','value'=>4],['op'=>'move','from'=>'/1e0','path'=>'/-']], '{"01":4,"-":2}'];
        yield 'unicode and percent sequences stay literal' => ['{"ä/雪":1,"%2F":2,"/":3}', [['op'=>'replace','path'=>'/ä~1雪','value'=>4],['op'=>'test','path'=>'/%2F','value'=>2]], '{"ä/雪":4,"%2F":2,"/":3}'];
        yield 'similar prefix is not descendant' => ['{"a":3,"ab":{}}', [['op'=>'move','from'=>'/a','path'=>'/ab/x']], '{"ab":{"x":3}}'];
    }

    #[DataProvider('successfulCases')]
    public function testSuccessfulEdgeCase(string $before, array $operations, string $expected): void
    {
        $target = JsonValue::decode($before);
        $snapshot = JsonValue::encode($target);
        $patch = JsonPatch::fromArray($operations);
        $serialized = JsonValue::encode($patch);
        $result = (new JsonPatchApplier())->apply($target, $patch, self::allOperations());
        self::assertTrue(JsonValue::equal(JsonValue::decode($expected), $result->value), JsonValue::encode($result->value));
        self::assertTrue($result->documentExists);
        self::assertSame($snapshot, JsonValue::encode($target));
        self::assertSame($serialized, JsonValue::encode($patch));
    }

    public static function rejectedCases(): iterable
    {
        yield 'replace at array length' => ['[3,4]', [['op'=>'replace','path'=>'/2','value'=>5]], 'missing_path'];
        yield 'remove at array length' => ['[3,4]', [['op'=>'remove','path'=>'/2']], 'missing_path'];
        yield 'move destination bounds after removal' => ['[3,4]', [['op'=>'move','from'=>'/0','path'=>'/2']], 'missing_path'];
        yield 'same missing source and destination is invalid' => ['{}', [['op'=>'move','from'=>'/absent','path'=>'/absent']], 'missing_path'];
        yield 'move descendant remains forbidden despite array shift' => ['[{"a":[]},{"b":[]}]', [['op'=>'move','from'=>'/0','path'=>'/0/b/0']], 'move_into_descendant'];
        yield 'move root into child' => ['{"a":{}}', [['op'=>'move','from'=>'','path'=>'/a/b']], 'move_into_descendant'];
        yield 'missing test is not null' => ['{}', [['op'=>'test','path'=>'/absent','value'=>null]], 'missing_path'];
        yield 'boolean is not a number' => ['{"x":true}', [['op'=>'test','path'=>'/x','value'=>1]], 'test_failed'];
        yield 'array order matters' => ['[3,4]', [['op'=>'test','path'=>'','value'=>[4,3]]], 'test_failed'];
        yield 'object extra member matters' => ['{"a":3,"b":4}', [['op'=>'test','path'=>'','value'=>(object)['a'=>3]]], 'test_failed'];
        yield 'cannot traverse a scalar' => ['{"x":3}', [['op'=>'add','path'=>'/x/y','value'=>4]], 'missing_parent'];
        yield 'missing patch array wrapper' => ['{}', ['op'=>'add','path'=>'/x','value'=>3], 'invalid_patch'];
        foreach (['01', '-1', '1.0', '1e0', '+1', ' 1', '1 ', '-', '999999999999999999999999'] as $index) {
            foreach (['test', 'replace', 'remove', 'copy', 'move'] as $op) {
                $operation = ['op'=>$op,'path'=>'/' . $index];
                if (in_array($op, ['test','replace'], true)) $operation['value'] = 4;
                if (in_array($op, ['copy','move'], true)) $operation = ['op'=>$op,'from'=>'/' . $index,'path'=>'/0'];
                yield "$op rejects index [$index]" => ['[3,4]', [$operation], 'invalid_array_index'];
            }
        }
    }

    #[DataProvider('rejectedCases')]
    public function testRejectedEdgeCase(string $before, array $operations, string $errorCode): void
    {
        $target = JsonValue::decode($before);
        $snapshot = JsonValue::encode($target);
        try {
            (new JsonPatchApplier())->apply($target, JsonPatch::fromArray($operations), self::allOperations());
            self::fail('Expected ' . $errorCode);
        } catch (PatchValidationException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
        self::assertSame($snapshot, JsonValue::encode($target));
    }

    private static function allOperations(): PatchApplyOptions
    {
        return new PatchApplyOptions(allowedOperations: ['add','remove','replace','move','copy','test'], allowRootReplacement: true);
    }
}
