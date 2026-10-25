<?php

/**
 * `nav_menu_item` against the importer's existing contract.
 */

declare(strict_types=1);

/*
 * Parity work against a live consumer, not a design decision.
 * `NavigationMenuImporter` already builds Statamic's `primary_navigation` from
 * WXR, so every field it reads has to survive this API unchanged (#9).
 *
 * Nothing here adds an endpoint. `nav_menu_item` is an eligible post type,
 * `nav_menu` is an eligible taxonomy, and `PostPayload` already serves raw
 * postmeta and grouped terms — so the contract was met before this file
 * existed and nothing proved it. That is the gap these tests close: the whole
 * `_menu_item_*` set is underscore-prefixed, so the blanket `NOT LIKE '\_%'`
 * filter D-007 rejects would break navigation silently and completely, and
 * until now no test would have noticed.
 *
 * The development install could not have proved it either way: it holds ten
 * `nav_menu_item` posts and **zero** of them are assigned to a menu, so its one
 * menu reports a count of zero. These tests build a real menu instead.
 */

/**
 * Build a menu with a parent item and a child, and return the ids.
 *
 * @return array{menu: int, parent: int, child: int, target: int}
 */
function makeMenuWithHierarchy(string $name, string $slug): array
{
    $menu = wp_create_nav_menu($name);

    assert(\is_int($menu), 'could not create the menu');

    // Renamed after creation: `wp_create_nav_menu()` derives the slug from the
    // name, and the importer selects its menu BY SLUG, so the test has to pin
    // the slug rather than accept whatever was derived.
    wp_update_term($menu, 'nav_menu', ['slug' => $slug]);

    $target = makePost(['post_title' => 'Linked Page', 'post_type' => 'page']);

    $parent = wp_update_nav_menu_item($menu, 0, [
        'menu-item-title' => 'Services',
        'menu-item-type' => 'custom',
        'menu-item-url' => 'https://uamshealth.test/services',
        'menu-item-status' => 'publish',
        'menu-item-position' => 1,
    ]);

    assert(\is_int($parent), 'could not create the parent item');

    $child = wp_update_nav_menu_item($menu, 0, [
        'menu-item-title' => 'Cardiology',
        'menu-item-type' => 'post_type',
        'menu-item-object' => 'page',
        'menu-item-object-id' => $target,
        'menu-item-parent-id' => $parent,
        'menu-item-status' => 'publish',
        'menu-item-position' => 2,
    ]);

    assert(\is_int($child), 'could not create the child item');

    return ['menu' => $menu, 'parent' => $parent, 'child' => $child, 'target' => $target];
}

/**
 * One `nav_menu_item` record from the posts endpoint.
 *
 * @return array<string, mixed>
 */
function navItem(int $id): array
{
    $request = new WP_REST_Request('GET', "/uamswp-migration/v1/posts/nav_menu_item/{$id}");
    $data = rest_do_request($request)->get_data();

    assert(\is_array($data), 'the posts endpoint did not return a record');

    /** @var array<string, mixed> $data */
    return $data;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves every `_menu_item_*` key the importer reads', function (): void {
    // Each one is underscore-prefixed, which is the whole of D-007's argument:
    // a filter that looks like hygiene breaks navigation entirely, and does it
    // without an error anywhere.
    $ids = makeMenuWithHierarchy('Parity Primary', 'parity-primary');

    $meta = navItem($ids['child'])['meta'];

    assert(\is_array($meta));

    foreach ([
        '_menu_item_menu_item_parent',
        '_menu_item_type',
        '_menu_item_object',
        '_menu_item_object_id',
        '_menu_item_url',
    ] as $key) {
        expect($meta)->toHaveKey($key);
    }

    // …and carrying the values the importer will act on, not merely present.
    expect($meta['_menu_item_type'])->toBe(['post_type'])
        ->and($meta['_menu_item_object'])->toBe(['page'])
        ->and($meta['_menu_item_object_id'])->toBe([(string) $ids['target']])
        ->and($meta['_menu_item_menu_item_parent'])->toBe([(string) $ids['parent']]);
});

it('exposes menu membership with the slug the importer selects by', function (): void {
    // `NavigationMenuImporter` reads `category[@domain='nav_menu']` and matches
    // by slug. An id would not survive a migration; a name is editable prose.
    $ids = makeMenuWithHierarchy('Parity Secondary', 'parity-secondary');

    $terms = navItem($ids['parent'])['terms'];

    assert(\is_array($terms));
    assert(\is_array($terms['nav_menu'] ?? null));

    $slugs = [];

    foreach ($terms['nav_menu'] as $term) {
        $slugs[] = \is_array($term) ? ($term['slug'] ?? null) : null;
    }

    expect($slugs)->toContain('parity-secondary');
});

it('serves every menu, leaving the choice to the consumer', function (): void {
    // The importer currently targets one menu and should keep choosing. An API
    // that served only the targeted one would make that choice for it, and
    // silently, since the payload would look complete.
    makeMenuWithHierarchy('Parity Alpha', 'parity-alpha');
    makeMenuWithHierarchy('Parity Beta', 'parity-beta');

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/terms/nav_menu');
    $data = rest_do_request($request)->get_data();

    assert(\is_array($data));

    $slugs = [];

    foreach ($data as $record) {
        $slugs[] = \is_array($record) ? ($record['slug'] ?? null) : null;
    }

    expect($slugs)->toContain('parity-alpha')
        ->and($slugs)->toContain('parity-beta');
});

it('carries the hierarchy and the ordering the menu was built with', function (): void {
    // The parent map is what `NavigationMenuImporter` rebuilds the tree from,
    // and `menu_order` is what orders siblings. Both are core fields rather
    // than meta, so they travel in a different part of the payload and could
    // regress independently.
    $ids = makeMenuWithHierarchy('Parity Tree', 'parity-tree');

    $parent = navItem($ids['parent']);
    $child = navItem($ids['child']);

    $childMeta = $child['meta'];

    assert(\is_array($childMeta));

    expect($childMeta['_menu_item_menu_item_parent'])->toBe([(string) $ids['parent']])
        // The parent is a root item: WordPress stores `0`, not an absent row.
        ->and(navItemParent($parent))->toBe('0')
        ->and(navItemOrder($child))->toBeGreaterThan(navItemOrder($parent));
});

/**
 * The `menu_order` of a record.
 *
 * @param  array<string, mixed>  $record
 */
function navItemOrder(array $record): int
{
    $order = $record['menu_order'] ?? null;

    return is_numeric($order) ? (int) $order : -1;
}

/**
 * The `_menu_item_menu_item_parent` value of a record.
 *
 * @param  array<string, mixed>  $record
 */
function navItemParent(array $record): string
{
    $meta = $record['meta'] ?? null;

    assert(\is_array($meta));

    $row = $meta['_menu_item_menu_item_parent'] ?? null;

    return \is_array($row) && isset($row[0]) && \is_string($row[0]) ? $row[0] : '';
}
