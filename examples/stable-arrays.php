<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Phore\JsonPatch\{JsonPatch, JsonPatchApplier, JsonValue, StableArrayView};

$original = JsonValue::decode('{"items":[{"id":"a","name":"Ada"},{"id":"b","name":"Lin"}]}');
$codec = new StableArrayView(['/items'=>'/id']);
$view = $codec->encode($original);
[$a, $b] = $view->items->{'$order'};
$patch = JsonPatch::fromArray([
    ['op'=>'test', 'path'=>'/items/$values/' . $a . '/id', 'value'=>'a'],
    ['op'=>'remove', 'path'=>'/items/$values/' . $a],
    ['op'=>'replace', 'path'=>'/items/$values/' . $b . '/name', 'value'=>'Lina'],
    ['op'=>'add', 'path'=>'/items/$values/new_c', 'value'=>(object) ['id'=>'c', 'name'=>'Chris']],
    ['op'=>'replace', 'path'=>'/items/$order', 'value'=>['new_c', $b]],
]);
$result = (new JsonPatchApplier())->apply($view, $patch, validator: static function (mixed $candidate) use ($codec): void {
    $codec->decode($candidate); // Validate references after the entire batch.
});
$edited = $codec->decode($result->value);
$expected = JsonValue::decode('{"items":[{"id":"c","name":"Chris"},{"id":"b","name":"Lina"}]}');
if (!JsonValue::equal($edited, $expected) || count($original->items) !== 2 || $original->items[0]->id !== 'a') {
    throw new RuntimeException('Unexpected stable-array result or mutated input.');
}
echo JsonValue::encode($edited), PHP_EOL;
