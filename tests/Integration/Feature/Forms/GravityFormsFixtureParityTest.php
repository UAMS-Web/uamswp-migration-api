<?php

/**
 * A committed Gravity Forms export, reproduced through the API.
 */

declare(strict_types=1);

/*
 * The acceptance criterion #11 actually asks for, and the one the synthetic
 * forms in `GravityFormsEndpointTest` cannot answer: a definition the importer
 * consumes today, put into a site and read back out through the endpoint,
 * covering fields, notifications and conditional logic.
 *
 * The corpus is not vendored — those exports are real production forms carrying
 * real notification recipients, and this repo does not commit production
 * artifacts. Point `UAMSWP_MIGRATION_API_TESTS_GF_FIXTURES` at
 * `wordpress-importer/src/Services/WordPressImport/Tests/Exports/GravityForms`
 * to run it; without that it skips, and says so.
 */

/**
 * The first exported form carrying fields, notifications, confirmations and
 * conditional logic, or null when the corpus is not reachable.
 *
 * Scanned rather than named: the corpus belongs to another repository and its
 * filenames are not this repo's to depend on.
 *
 * @return array<string, mixed>|null
 */
function gfFixtureForm(): ?array
{
    $dir = gravityFormsFixtureDir();

    if ($dir === '') {
        return null;
    }

    $files = glob(rtrim($dir, '/').'/*.json');

    foreach (is_array($files) ? $files : [] as $file) {
        $contents = file_get_contents($file);

        if (! is_string($contents)) {
            continue;
        }

        $decoded = json_decode($contents, true);

        foreach (is_array($decoded) ? $decoded : [] as $key => $form) {
            if ($key === 'version') {
                continue;
            }

            if (! is_array($form)) {
                continue;
            }

            $fields = $form['fields'] ?? null;

            if (! is_array($fields)) {
                continue;
            }

            if ($fields === []) {
                continue;
            }

            $conditional = 0;

            foreach ($fields as $field) {
                if (is_array($field) && ! empty($field['conditionalLogic'])) {
                    $conditional++;
                }
            }

            if ($conditional > 0 && ! empty($form['notifications']) && ! empty($form['confirmations'])) {
                /** @var array<string, mixed> $form */
                return $form;
            }
        }
    }

    return null;
}

/**
 * One form as a CONSUMER receives it — encoded to JSON and decoded back.
 *
 * Not `get_data()`, and the difference is the whole point of this test.
 * `GFAPI::get_form()` returns each field as a `GF_Field` subclass object, not
 * an array, so a PHP-side comparison against the fixture's plain arrays
 * measures the wrong thing. What ships is the JSON, and round-tripping it here
 * asserts the definition survives that encoding — which is the actual contract
 * and the only shape the importer will ever see.
 *
 * Defined here rather than reused from `GravityFormsEndpointTest`: a test file
 * that only works when a sibling file happens to have loaded first is coupled
 * to Pest's file ordering, and this one is run on its own whenever somebody
 * points the fixture variable at the corpus.
 *
 * @return array<string, mixed>
 */
function gfFixtureShow(int $id): array
{
    $response = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1/forms/gravity/'.$id));

    $encoded = wp_json_encode($response->get_data());

    assert(is_string($encoded), 'the served form did not survive JSON encoding');

    $decoded = json_decode($encoded, true);

    assert(is_array($decoded), 'the served form did not decode back to an array');

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * A stable digest of a structure, for comparing without printing it.
 *
 * @param  mixed  $value
 */
function gfDigest($value): string
{
    $json = json_encode($value);

    return md5(is_string($json) ? $json : '');
}

beforeEach(function (): void {
    if (! gravityFormsOnDisk()) {
        $this->markTestSkipped('Gravity Forms is not in the surrounding install.');
    }

    if (gravityFormsFixtureDir() === '') {
        $this->markTestSkipped("Set UAMSWP_MIGRATION_API_TESTS_GF_FIXTURES to the importer's GravityForms exports directory.");
    }

    actAsSuperAdmin();

    foreach (GFAPI::get_forms(null, null, 'id', 'ASC') as $form) {
        $id = is_array($form) ? ($form['id'] ?? null) : null;

        if (is_numeric($id)) {
            GFAPI::delete_form((int) $id);
        }
    }
});

it('reproduces a committed export, with its fields, notifications and conditional logic', function (): void {
    $fixture = gfFixtureForm();

    if ($fixture === null) {
        $this->markTestSkipped('No export in that directory carries fields, notifications, confirmations and conditional logic.');
    }

    $id = GFAPI::add_form($fixture);

    assert(is_int($id), 'Gravity Forms refused to import the committed export');

    $served = gfFixtureShow($id);

    // The title, verbatim.
    expect($served['title'])->toBe($fixture['title'] ?? null);

    /*
     * Every field, by type and label, in order. Compared as a projection rather
     * than whole-object: `add_form()` legitimately rewrites `formId` and can
     * normalize defaults, and asserting on those would test Gravity Forms
     * rather than this endpoint.
     */
    $project = static function (mixed $fields): array {
        $out = [];

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field)) {
                $out[] = [
                    'type' => $field['type'] ?? null,
                    'label' => $field['label'] ?? null,
                    'isRequired' => (bool) ($field['isRequired'] ?? false),
                ];
            }
        }

        return $out;
    };

    expect($project($served['fields'] ?? null))->toBe($project($fixture['fields'] ?? null));

    /*
     * Conditional logic, field by field. This is the block a naive read loses —
     * it is nested inside each field rather than sitting on the form — and the
     * criterion names it explicitly. Digested rather than compared inline so a
     * failure cannot dump a production form into the output.
     */
    $logic = static function (mixed $fields): array {
        $out = [];

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field) && ! empty($field['conditionalLogic'])) {
                $out[] = gfDigest($field['conditionalLogic']);
            }
        }

        return $out;
    };

    $fixtureLogic = $logic($fixture['fields'] ?? null);

    expect($fixtureLogic)->not->toBeEmpty();
    expect($logic($served['fields'] ?? null))->toBe($fixtureLogic);

    /*
     * Notifications and confirmations reproduce whole, compared BY VALUE rather
     * than including the array keys.
     *
     * The keying legitimately differs and it is not a loss: an export indexes
     * its notifications `0, 1, 2…`, and Gravity Forms re-keys them by each
     * notification's own `id` on import — so the same notification arrives at
     * `['5ad1051c9bdd5']` instead of `[0]` with every field, `id` included,
     * byte-identical. Asserting on the keys would fail on Gravity Forms'
     * indexing while proving nothing about the data.
     *
     * Digested rather than compared inline, deliberately: a notification
     * carries recipient addresses, and a failing inline comparison would print
     * them into a log. The count is asserted separately so a failure still says
     * something useful without dumping anything.
     */
    $byValue = static fn (mixed $block): array => array_values((array) ($block ?? []));

    expect(\count($byValue($served['notifications'] ?? null)))
        ->toBe(\count($byValue($fixture['notifications'] ?? null)));

    expect(gfDigest($byValue($served['notifications'] ?? null)))
        ->toBe(gfDigest($byValue($fixture['notifications'] ?? null)), 'notifications did not reproduce');

    expect(\count($byValue($served['confirmations'] ?? null)))
        ->toBe(\count($byValue($fixture['confirmations'] ?? null)));

    expect(gfDigest($byValue($served['confirmations'] ?? null)))
        ->toBe(gfDigest($byValue($fixture['confirmations'] ?? null)), 'confirmations did not reproduce');
});
