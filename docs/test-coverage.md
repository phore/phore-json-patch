# Test coverage and upstream comparison

The extracted baseline contained 13 PHPUnit tests in `JsonPatchTest` and
`StableArrayViewTest`, with several assertions/cases inside each method. It already
covered RFC appendix examples, object-copy isolation, null vs missing values,
escaping, atomic failure, policies, root deletion/recreation and stable transport.

The comparison adds 112 upstream data sets and 75 independently written edge-case
data sets. Total: **200 tests, 628 assertions, 4 upstream-disabled cases skipped**
with PHPUnit 13.3.3 on PHP 8.5.10. No additional runtime changes were needed to
pass these cases. Counts describe this revision, not a promise of complete RFC
conformance or exhaustive input coverage.

## Sources pinned for reproducibility

- [JSON Patch Tests, revision 2a928f9](https://github.com/json-patch/json-patch-tests/tree/2a928f9044aad35c74e2788d498bcf2c6b91adea):
  unchanged `tests.json` (95 records) and `spec_tests.json` (17 records), plus its
  README attribution and Apache-2.0 license, in `test/fixtures/json-patch-tests/`.
  `ConformanceTest` executes each enabled case with all six operations and root
  mutations enabled, checks the expected result/error and verifies unchanged input
  on both success and failure. Test runs require no network access.
- [python-json-patch tests, revision d8e1a6e](https://github.com/stefankoegl/python-json-patch/blob/d8e1a6e244728c04229d601bc9a384d9b034c603/tests.py):
  reviewed `ApplyPatchTestCase`, `ConflictTests`, `InvalidInputTests` and operation
  structure tests. No Python source is copied into this package.
- [fast-json-patch core tests, revision 9d313ac](https://github.com/Starcounter-Jack/JSON-Patch/blob/9d313ac01916e525e9204074f06e5295edec491b/test/spec/coreSpec.mjs):
  reviewed root operations, copy-reference isolation, sequential application and
  JavaScript-specific behavior. No JavaScript source is copied into this package.

## Gaps closed

| Previously absent or only partially tested | Evidence reviewed | Added coverage |
|---|---|---|
| Empty patches, top-level object/list transitions | Shared corpus | `ConformanceTest` |
| Add at array length vs beyond length; replace/remove at length | Shared corpus; Python `ConflictTests` | Corpus and `EdgeCaseTest::rejectedCases` |
| Repeated removals and index shifts | Shared corpus; Python array operations | Corpus and `sequential removals reindex arrays` |
| Move backwards, append, same location, overwrite, destination bounds after removal | Shared corpus; Python move tests | `EdgeCaseTest` success/rejection data sets |
| Move/copy child object or array to root; self-root operations | fast-json-patch root tests | Six root move/copy data sets |
| Top-level scalar replacement and whole-document test | Disabled shared cases; Python/fast-json-patch root tests | Two enabled independent data sets |
| Copy root into child; nested-list isolation when either branch changes | Python `test_copy_mutable`; fast-json-patch copy-reference test | Two independent copy regressions |
| Null source for move/copy/remove, missing values and malformed operations | Shared corpus; Python operation structure tests | Corpus plus missing-wrapper and missing-test regressions |
| Numeric-looking object keys vs array indices | Shared corpus | Literal-key test and 45 rejection combinations across five operations |
| Unicode, literal percent sequences, prefix vs segment ancestry | Shared pointer cases; Python escape/unicode tests | Independent pointer and move regressions |
| Boolean/number mismatch, reordered arrays, unequal object member sets | Shared tests; fast-json-patch equality checks | Explicit rejection data sets with error codes |
| Scalar parent traversal and invalid same-source move | Python conflict tests | Explicit rejection data sets and unchanged-input checks |

All 75 `EdgeCaseTest` data sets are independently expressed in PHP with local
examples. Rejection tests assert the precise local `errorCode`; the shared corpus
only requires rejection because its error strings are descriptive, not an API.

## Intentional differences and scope

Four shared records are disabled upstream, and the runner preserves those flags:
`tests.json` indices 10 (scalar root), 56 (whole-document test), 85 (duplicate `op`)
and `spec_tests.json` index 13 (duplicate `op`). Indices are zero-based. Our own
tests cover the first two successfully. Duplicate JSON member detection is not
provided by the native PHP JSON decoder; those two raw-syntax tests remain skipped.

Python's `test_move_array_item_into_other_item` accepts moving `/0` into `/0/bar/0`
because the destination would refer to the next element after removal. We reject
it: [RFC 6902 section 4.4](https://www.rfc-editor.org/rfc/rfc6902#section-4.4) prohibits `from` being a proper prefix of `path`.
`move descendant remains forbidden despite array shift` records this difference.
Moves to non-descendant paths still resolve destinations after source removal.

Defaults are stricter than unrestricted RFC execution: move/copy and root mutations
need opt-in; limits and protected paths also apply. The existing typed operation
contract rejects non-null unused `from`/`value` fields. Unknown extension members
are ignored. The corpus does not establish acceptance of every possible extension
member combination. Missing-path tests use local `missing_path` errors, not Python's
exception taxonomy.

Diff generation, minimal-patch optimization, Python custom types and JavaScript
`undefined`, prototypes, observers and in-place mutation APIs are outside this
package's API. Stable-ID transport and its policies are package-specific and
remain covered by the transferred local tests. External libraries' behavior is
evidence for useful test cases, not an authority overriding the RFC or local API.

## Updating the corpus

Review a new upstream revision and license, replace both JSON files byte-for-byte,
retain README attribution/license, update the pinned link and disabled-case notes,
then run `composer test`. Do not silently change expected outputs to make a case
pass. Re-run `composer examples` to validate the documented workflows.
