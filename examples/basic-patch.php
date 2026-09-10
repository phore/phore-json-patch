<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Phore\JsonPatch\{JsonPatch, JsonPatchApplier, JsonValue};

$original = JsonValue::decode('{"title":"Draft","tags":["php"],"settings":{"a/b":false}}');
$patch = JsonPatch::fromArray([
    ['op'=>'test', 'path'=>'/title', 'value'=>'Draft'],
    ['op'=>'replace', 'path'=>'/title', 'value'=>'Published'],
    ['op'=>'add', 'path'=>'/tags/-', 'value'=>'json-patch'],
    ['op'=>'replace', 'path'=>'/settings/a~1b', 'value'=>true],
]);
$result = (new JsonPatchApplier())->apply($original, $patch);
$expected = JsonValue::decode('{"title":"Published","tags":["php","json-patch"],"settings":{"a/b":true}}');
if (!JsonValue::equal($expected, $result->value) || $original->title !== 'Draft') {
    throw new RuntimeException('Unexpected patch result or mutated input.');
}
echo JsonValue::encode($result->value), PHP_EOL;
