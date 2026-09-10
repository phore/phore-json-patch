<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Phore\JsonPatch\{JsonPatch, JsonPatchApplier, JsonValue, PatchApplyOptions, PatchConflictException, PatchTestFailedException};

$original = (object) ['title'=>'Draft', 'version'=>1];
$hash = JsonValue::hash($original);
$applier = new JsonPatchApplier();
try {
    $applier->apply($original, JsonPatch::fromArray([
        ['op'=>'replace', 'path'=>'/title', 'value'=>'Published'],
        ['op'=>'test', 'path'=>'/version', 'value'=>2],
    ]));
    throw new RuntimeException('Expected test failure.');
} catch (PatchTestFailedException $exception) {
    if ($exception->operationIndex !== 1 || JsonValue::hash($original) !== $hash) {
        throw new RuntimeException('Atomicity check failed.');
    }
    echo $exception->errorCode, ': original unchanged', PHP_EOL;
}

$newer = JsonValue::copy($original);
$newer->version = 2;
try {
    $applier->apply($newer, new JsonPatch([]), new PatchApplyOptions(expectedHash: $hash));
    throw new RuntimeException('Expected hash conflict.');
} catch (PatchConflictException $exception) {
    echo $exception->errorCode, ': stale snapshot rejected', PHP_EOL;
}
