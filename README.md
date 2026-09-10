# Phore JSON Patch

Atomic JSON Patch for PHP 8.5+, with RFC 6901 pointers, all six RFC 6902
operations, configurable limits, optimistic conflict checks and an optional
stable-ID representation for editing arrays. Runtime dependencies: PHP and JSON
only. No AI provider, HTTP client or schema library is required.

Extracted from the deterministic patch core of `phore/ai-harness`. Typed object
hydration, schema validation and AI-generated patch envelopes remain in that
package. Public classes now use `Phore\JsonPatch\`.

## Installation

The initial package is being prepared for publication. After it is available on
Packagist:

```sh
composer require phore/json-patch
```

For development before publication, clone this repository and run
`composer install`, `composer test` and `composer examples`.

## Apply a patch

```php
require 'vendor/autoload.php';

use Phore\JsonPatch\{JsonPatch, JsonPatchApplier};

$original = (object) ['title' => 'Draft', 'tags' => ['php']];
$patch = JsonPatch::fromArray([
    ['op' => 'test', 'path' => '/title', 'value' => 'Draft'],
    ['op' => 'replace', 'path' => '/title', 'value' => 'Published'],
    ['op' => 'add', 'path' => '/tags/-', 'value' => 'json-patch'],
]);
$result = (new JsonPatchApplier())->apply($original, $patch);
$edited = $result->value;
// $edited: {"title":"Published","tags":["php","json-patch"]}
// $original still contains "Draft" and ["php"].
```

Operations run sequentially against a detached candidate. Any error aborts the
batch without changing the input. Operation values are also copied on construction
and access, and `copy` creates independent values. An optional `validator` callback
receives a separate copy of the final candidate once; returning `false` or throwing
rejects it. This does not roll back external side effects in application callbacks.

JSON objects are `stdClass`, arrays are PHP lists. `JsonValue::decode()` preserves
that distinction; use `(object) []` for `{}` and `[]` for `[]`. Associative PHP
arrays normalize to objects. Null is a value, distinct from a missing member.
`test` compares object members without ordering, arrays with ordering and numbers
numerically; strings and booleans are distinct from numbers.

## Pointers and operations

| Operation | Meaning | Required fields |
|---|---|---|
| `add` | Insert into a list or create/overwrite an object member | `op`, `path`, `value` |
| `remove` | Remove an existing value | `op`, `path` |
| `replace` | Replace an existing value | `op`, `path`, `value` |
| `move` | Remove source, then add at destination | `op`, `path`, `from` |
| `copy` | Copy source by value into destination | `op`, `path`, `from` |
| `test` | Reject the batch unless the existing value is equal | `op`, `path`, `value` |

`JsonPointer::get($document, $pointer)` reads a value; `JsonPointer::escape($key)`
escapes a property name. The empty pointer `''` addresses the root, `/` the
empty-string property, `~0` a tilde and `~1` a slash. URI fragments and percent
decoding are not supported. Array indices must be canonical nonnegative decimal
integers without leading zeros. `-` appends for add destinations, including those
of move/copy. Parents must already exist. Array indices always refer to the state
after preceding operations; move resolves the destination after removal.

All six operations are implemented. Defaults deliberately allow only
`add`, `remove`, `replace`, `test`, and forbid root mutations. To enable the full
operation set and root changes:

```php
use Phore\JsonPatch\PatchApplyOptions;

$options = new PatchApplyOptions(
    allowedOperations: ['add', 'remove', 'replace', 'move', 'copy', 'test'],
    allowRootReplacement: true,
);
$result = (new JsonPatchApplier())->apply($original, $patch, $options);
```

Removing the root sets `documentExists` to `false`; this differs from JSON null.
Only a subsequent root `add` can recreate it. Moving a path into its own descendant
is rejected, including array paths whose indices would shift. `fromArray()` ignores
unrecognized extension members; the typed operation contract rejects a non-null
`from` on add/remove/replace/test and non-null `value` on remove/move/copy.

## Limits and conflict protection

| `PatchApplyOptions` property | Default | Effect |
|---|---|---|
| `atomic` | `true` | Non-atomic application is unsupported. |
| `maxOperations` | `100` | Bounds batch length. |
| `maxPatchBytes` | `65536` | Bounds serialized native patch size. |
| `maxDocumentBytes` | `4194304` | Bounds input and each intermediate document. |
| `maxDepth` | `64` | Bounds documents and pointers. |
| `allowedOperations` | add, remove, replace, test | Explicit operation allowlist. |
| `allowRootReplacement` | `false` | Enables root mutations. |
| `forbiddenPaths` | `[]` | Protects overlapping paths and indirect array shifts. |
| `requireTests` | `'none'` | `'arrays'` guards positional edits; `'all'` guards every remove/replace/move source. |
| `expectedHash` | `null` | Requires the current input hash to match. |
| `dryRun` | `false` | Returns a validated candidate with `dry_run` status. |

A required guard must be the immediately preceding successful `test`. It must
cover the mutation or an identity/value inside the exact array element being
removed/replaced. Guards do not make numeric indices stable. Protected paths block
reads as well as mutations, including ancestors and copy/move sources.

Use `JsonValue::hash($snapshot)` as `expectedHash`. Hashes sort object keys
recursively and preserve list order; this is a local format, not RFC 8785.
A missing document hashes as SHA-256 of `undefined`. Persistence code must compare
and swap the expected version/hash in its own transaction; this library only
checks the supplied in-memory document. Dry run also leaves the input unchanged.

`PatchApplyResult` exposes `value`, `documentExists`, `status`, `oldHash`, `newHash`,
`operationCount`, `changedPaths` and `patch`. Changed paths include explicit
no-op replacements and move sources; they are not a minimal semantic diff.

Exceptions derive from `PatchValidationException`: `PatchTestFailedException`,
`PatchLimitException` and `PatchConflictException`. Operation failures expose
`errorCode`, `operationIndex` (zero-based), `op` and `path`, without embedding
values in messages. Configuration errors throw `InvalidArgumentException`.

## Stable array addressing

`StableArrayView` encodes lists into ordinary JSON objects with `$order` and
`$values`. Patches remain standard JSON Patch; IDs replace positional addressing
inside this transport view.

```json
{"items":{"$order":["item_a","item_b"],"$values":{"item_a":{"id":"a"},"item_b":{"id":"b"}}}}
```

The keys above illustrate the shape. Actual encoding produces opaque deterministic
keys from scalar `id`, `key` or `uuid` values. Missing/duplicate automatic identities
get request-local ephemeral keys. `new StableArrayView(['/items' => '/id'])`
requires a present, unique scalar identity at that pointer for each item. Explicit
identities may use nested pointers such as `/metadata/uuid`.

1. Encode the snapshot and retain the generated keys.
2. Edit `/items/$values/<key>/name` to change a particular item.
3. Add/remove both the value entry and its `$order` reference in the batch.
4. Replace `$order` to reorder items, then decode the final candidate.

Business-ID changes do not retarget existing transport keys during a batch.
Ephemeral keys have no cross-request meaning. Every nested/new list uses the same
wrapper, except `$order` itself. Decoding rejects duplicate references, missing
values, orphan values, malformed wrappers and unwrapped lists. Original objects
with reserved `$order` or `$values` members cannot use this mode.

Validate the final transport in a callback that calls `$codec->decode($candidate)`
and returns void, as in the executable example. Intermediate steps may temporarily
have unmatched references; validation belongs after the batch.

## Executable examples and tests

- [Basic patch and escaped pointer](examples/basic-patch.php)
- [Atomic failure and stale-hash conflict](examples/atomic-failure.php)
- [Combined stable-array deletion, edit, addition and reorder](examples/stable-arrays.php)
- [Coverage comparison, upstream sources and deliberate differences](docs/test-coverage.md)

```sh
composer validate --strict
composer test
composer examples
```

CI executes the suite and examples on PHP 8.5. The package includes the unchanged
Apache-2.0 JSON Patch test corpus with its attribution and license; see
`test/fixtures/json-patch-tests`. Runtime source retains the MIT package license.
