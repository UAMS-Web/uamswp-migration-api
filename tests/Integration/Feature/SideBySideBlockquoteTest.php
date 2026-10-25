<?php

/**
 * The Side-by-Side Image & Text block's Blockquote with Citation layouts (#359).
 *
 * `UAMS-Web/uams-2020` v1.7.0 added two text layouts to the block (#612 there,
 * shipped by its #613): `blockquote-citation`, which hides the Call to Action
 * Button, and `blockquote-citation-link`, which shows it. They store their
 * values in two places, and the importer reads both from this API:
 *
 * - **The `acf/image-side` block**, registered with `acf_block_version` 3 and
 *   no `usePostMeta`, keeps every field value in its block comment inside
 *   `post_content`. The payload serves `post_content` as stored (D-004), so
 *   these tests pin that the stored string comes back byte for byte and that
 *   the values parse out of it unchanged.
 * - **The older modules template** (`templates/modules.php`) reaches the same
 *   field definitions through the `uamswp_modules` flexible-content field, which
 *   ACF stores as post meta. That is the one place these values live outside
 *   `post_content`, and the payload's `meta` carries every row (D-005, D-007).
 *
 * No code changed for #359: both routes already carried the values, and these
 * tests are what now says so.
 */

declare(strict_types=1);

/**
 * The block comment the editor stores for one `acf/image-side` block.
 *
 * Built with core's `serialize_block()`, which is what the block editor's save
 * runs, so the attribute JSON carries the same escaping a real page has:
 * `<`, `>`, `&` and `"` as `\u` escapes, and non-ASCII characters as written.
 *
 * @param  array<string, mixed>  $data  The block's ACF data: values and `_` key references.
 */
function sideBySideBlock(array $data): string
{
    return serialize_block([
        'blockName' => 'acf/image-side',
        'attrs' => [
            'name' => 'acf/image-side',
            'data' => $data,
            'align' => 'full',
            'mode' => 'preview',
        ],
        'innerBlocks' => [],
        'innerHTML' => '',
        'innerContent' => [],
    ]);
}

/**
 * The fields every layout of the block shares, as the block stores them.
 *
 * Field keys carry the `_b` suffix the theme gives its block field group.
 *
 * @return array<string, mixed>
 */
function sideBySideCommonData(string $layout): array
{
    return [
        'side_text_layout' => $layout,
        '_side_text_layout' => 'field_side_layout_b',
        'side_heading' => 'Unmatched Results',
        '_side_heading' => 'field_side_heading_b',
        'side_image_side_image_image' => 4242,
        '_side_image_side_image_image' => 'field_side_image_image_b',
        'side_image_position' => 'left',
        '_side_image_position' => 'field_side_image_position_b',
    ];
}

/**
 * The citation fields, with an optional speaker title.
 *
 * The values carry what the theme strips or escapes on output and the payload
 * must not: typed quotation marks at both ends, a curly apostrophe inside, an
 * ellipsis, an ampersand and a comma-separated credential list.
 *
 * @return array<string, mixed>
 */
function sideBySideCitation(string $quoteField, string $title): array
{
    return [
        $quoteField => '"When the competitive drive of a Razorback’s season meets the expertise & care of a UAMS provider… great results manifest."',
        "_{$quoteField}" => "field_{$quoteField}_b",
        'side_quote_speaker_name' => 'Michael Miller, DC, MS',
        '_side_quote_speaker_name' => 'field_side_quote_speaker_name_b',
        'side_quote_speaker_title' => $title,
        '_side_quote_speaker_title' => 'field_side_quote_speaker_title_b',
    ];
}

/**
 * The Call to Action Button group, as the block stores a group's sub-fields.
 *
 * @return array<string, mixed>
 */
function sideBySideButton(): array
{
    return [
        'side_cta_side_cta_text' => 'Find a Sports Medicine Provider',
        '_side_cta_side_cta_text' => 'field_side_cta_text_b',
        'side_cta_side_cta_url' => ['title' => 'Find a Provider', 'url' => 'https://uamshealth.com/sports/?a=1&b=2', 'target' => ''],
        '_side_cta_side_cta_url' => 'field_side_cta_url_b',
        'side_cta_side_cta_description' => 'Opens the sports medicine provider list',
        '_side_cta_side_cta_description' => 'field_side_cta_description_b',
    ];
}

/**
 * Store a post whose `post_content` is exactly the given string.
 *
 * Written to the row directly rather than through `wp_insert_post()`, which
 * strips slashes from its input, removing the backslashes of the attribute JSON's
 * `\u` escapes. The editor slashes before it saves, so the row a real page
 * holds is this string, and that row is what the payload must reproduce.
 */
function sideBySidePost(string $content): int
{
    $wpdb = testDatabase();
    $id = makePost();

    $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $id]);
    clean_post_cache($id);

    return $id;
}

/**
 * The ACF data of the one block in a payload's `content`, parsed back out.
 *
 * @return array<string, mixed>
 */
function sideBySideServedData(WP_REST_Response $response): array
{
    $content = payload($response, 'content');

    assert(is_string($content), 'the payload carried no string content');

    $blocks = array_values(array_filter(
        parse_blocks($content),
        static fn (array $block): bool => $block['blockName'] === 'acf/image-side',
    ));

    assert(count($blocks) === 1, 'the payload content did not hold exactly one acf/image-side block');

    $data = $blocks[0]['attrs']['data'] ?? null;

    assert(is_array($data), 'the served block carried no data attribute');

    $served = [];

    foreach ($data as $key => $value) {
        assert(is_string($key), 'the served block data carried a non-string key');
        $served[$key] = $value;
    }

    return $served;
}

/**
 * Dispatch a GET for one post, authenticated.
 */
function sideBySideGet(int $id, string $type = 'post'): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request('GET', "/uamswp-migration/v1/posts/{$type}/{$id}"));
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves a Blockquote with Citation block with every value unchanged', function (): void {
    $data = sideBySideCommonData('blockquote-citation')
        + sideBySideCitation('side_quote_text', 'Chiropractor, UAMS Health Orthopaedic & Sports Medicine Clinic');
    $content = sideBySideBlock($data);
    $id = sideBySidePost($content);

    $response = sideBySideGet($id);

    // The control that the fixture is the hard case: the attribute JSON really
    // does carry escapes a careless round trip would lose.
    expect($content)->toContain('\u0022When')
        ->and($content)->toContain('\u0026')
        ->and($response->get_status())->toBe(200)
        ->and(payload($response, 'content'))->toBe($content)
        ->and(sideBySideServedData($response))->toBe($data);
});

it('serves a Blockquote with Citation block with no speaker title', function (): void {
    // The title is optional, and the theme hides it until a name is entered.
    // An empty title must arrive as an empty string, which the importer can
    // tell apart from a value that was dropped.
    $data = sideBySideCommonData('blockquote-citation') + sideBySideCitation('side_quote_text', '');
    $content = sideBySideBlock($data);
    $id = sideBySidePost($content);

    $served = sideBySideServedData(sideBySideGet($id));

    expect($served)->toBe($data)
        ->and($served)->toHaveKey('side_quote_speaker_title')
        ->and($served['side_quote_speaker_title'])->toBe('');
});

it('serves a Blockquote with Citation and Link block with its own quote field and the button', function (): void {
    // The with-button layout has a separate quote field, because a character
    // limit belongs to a field and the button takes space the quote would use.
    $data = sideBySideCommonData('blockquote-citation-link')
        + sideBySideCitation('side_quote_text_link', 'Physician, UAMS Health Orthopaedics and Sports Medicine')
        + sideBySideButton();
    $content = sideBySideBlock($data);
    $id = sideBySidePost($content);

    $response = sideBySideGet($id);
    $served = sideBySideServedData($response);

    expect(payload($response, 'content'))->toBe($content)
        ->and($served)->toBe($data)
        ->and($served)->not->toHaveKey('side_quote_text');
});

it('serves button values a no-button block still holds from another layout', function (): void {
    // The theme's no-button layout skips a button saved under another layout
    // when it renders. The values are still in the block's data, and serving
    // them is the importer's to decide, not this API's.
    $data = sideBySideCommonData('blockquote-citation')
        + sideBySideCitation('side_quote_text', 'Orthopaedic Surgeon')
        + sideBySideButton();
    $content = sideBySideBlock($data);
    $id = sideBySidePost($content);

    $served = sideBySideServedData(sideBySideGet($id));

    expect($served)->toBe($data)
        ->and($served['side_cta_side_cta_text'])->toBe('Find a Sports Medicine Provider');
});

it('serves the Body only and Body and Link List layouts unchanged', function (): void {
    $bodyOnly = sideBySideCommonData('body-only') + [
        'side_layout_body_text' => 'Body text with an ampersand & a <b>tag</b>.',
        '_side_layout_body_text' => 'field_side_layout_body_text_b',
    ];
    $linkList = sideBySideCommonData('link-list') + [
        'side_layout_link_text' => 'Lead-in to the links.',
        '_side_layout_link_text' => 'field_side_layout_link_text_b',
        'side_link_list' => 1,
        '_side_link_list' => 'field_side_link_list_b',
        'side_link_list_0_side_link_list_text' => 'Appointments',
        '_side_link_list_0_side_link_list_text' => 'field_side_link_list_text_b',
        'side_link_list_0_side_link_list_url' => ['title' => 'Appointments', 'url' => 'https://uamshealth.com/appointments/', 'target' => ''],
        '_side_link_list_0_side_link_list_url' => 'field_side_link_list_url_b',
    ];

    foreach ([$bodyOnly, $linkList] as $data) {
        $content = sideBySideBlock($data);
        $response = sideBySideGet(sideBySidePost($content));

        expect(payload($response, 'content'))->toBe($content)
            ->and(sideBySideServedData($response))->toBe($data);
    }
});

it("carries the modules template's blockquote values in `meta`, where they live outside `post_content`", function (): void {
    // The older modules template stores the same fields as `uamswp_modules`
    // flexible-content rows in post meta, keyed with the `_m` suffix. Nothing
    // about them is in `post_content`, so `meta` is the only route.
    $id = makePost(['post_type' => 'page', 'post_content' => '']);

    // ACF stores the layout list as an array, which WordPress serializes once.
    add_post_meta($id, 'uamswp_modules', ['modules_image_side']);

    $rows = [
        '_uamswp_modules' => 'field_uamswp_modules',
        'uamswp_modules_0_side_text_layout' => 'blockquote-citation-link',
        '_uamswp_modules_0_side_text_layout' => 'field_side_layout_m',
        'uamswp_modules_0_side_quote_text_link' => '“I have full trust & confidence in UAMS for my athletic care.”',
        '_uamswp_modules_0_side_quote_text_link' => 'field_side_quote_text_link_m',
        'uamswp_modules_0_side_quote_speaker_name' => 'Michael Miller, DC, MS',
        '_uamswp_modules_0_side_quote_speaker_name' => 'field_side_quote_speaker_name_m',
        'uamswp_modules_0_side_quote_speaker_title' => '',
        '_uamswp_modules_0_side_quote_speaker_title' => 'field_side_quote_speaker_title_m',
    ];

    foreach ($rows as $key => $value) {
        // `add_post_meta()` strips slashes; the stored row must be the value itself.
        add_post_meta($id, $key, wp_slash($value));
    }

    $meta = payload(sideBySideGet($id, 'page'), 'meta');

    assert(is_array($meta), 'the payload carried no meta map');

    expect($meta['uamswp_modules'] ?? null)->toBe([serialize(['modules_image_side'])]);

    foreach ($rows as $key => $value) {
        expect($meta)->toHaveKey($key)
            ->and($meta[$key])->toBe([$value]);
    }
});
