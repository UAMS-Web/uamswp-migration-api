<?php

declare(strict_types=1);

/*
 * #343: the `adversarial-review` rule carries the review questions ported from
 * `UAMS-Web/uams-statamic-archive#2712`, so a later edit cannot drop one without a
 * failure.
 *
 * Each case pins one checklist by the items a reviewer answers, not by its
 * prose, so rewording a question passes and removing one fails.
 */

$adversarialReviewRulePath = dirname(__DIR__, 2).'/.claude/rules/adversarial-review.md';

/**
 * One `###` subsection of the rule's "Review questions" section, up to the
 * next heading.
 *
 * @param  string  $rule  The rule file's contents.
 * @param  string  $heading  The subsection heading's opening words.
 */
function adversarialReviewSubsection(string $rule, string $heading): string
{
    $pattern = '/^### '.preg_quote($heading, '/').'[^\n]*\n(.*?)(?=^##)/ms';

    if (preg_match($pattern, $rule, $match) !== 1) {
        throw new RuntimeException(sprintf('No "%s" subsection found.', $heading));
    }

    return $match[1];
}

/**
 * The rule file's contents.
 */
function adversarialReviewRule(string $path): string
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read [{$path}].");
    }

    return $contents;
}

it('asks the four error-handling questions', function () use ($adversarialReviewRulePath): void {
    $section = adversarialReviewSubsection(adversarialReviewRule($adversarialReviewRulePath), 'Error handling');

    expect($section)->toContain('**Broad catches.**', '**Silent fallbacks.**', '**Masking defaults.**', '**Log and continue.**');
});

it('names the WordPress forms of a masking default', function () use ($adversarialReviewRulePath): void {
    $section = adversarialReviewSubsection(adversarialReviewRule($adversarialReviewRulePath), 'Error handling');

    expect($section)->toContain('is_wp_error()', 'get_option()', 'get_post_meta()');
});

it("keeps the auth gate's uniform 401 out of the error-message question", function () use ($adversarialReviewRulePath): void {
    $section = adversarialReviewSubsection(adversarialReviewRule($adversarialReviewRulePath), 'Error handling');

    expect($section)->toContain('uniform `401` is deliberate');
});

it('rates a missing test by the regression it would catch', function () use ($adversarialReviewRulePath): void {
    $section = adversarialReviewSubsection(adversarialReviewRule($adversarialReviewRulePath), 'Test gaps');

    expect($section)->toContain('name the regression a test would catch', '**Must add:**', '**Skip:**');
});

it('checks every claim in a comment against the code', function () use ($adversarialReviewRulePath): void {
    $section = adversarialReviewSubsection(adversarialReviewRule($adversarialReviewRulePath), 'Comment accuracy');

    expect($section)->toContain('check each statement against the code it describes');
});

it('confirms a finding independently and drops what is not confirmed', function () use ($adversarialReviewRulePath): void {
    $section = adversarialReviewSubsection(adversarialReviewRule($adversarialReviewRulePath), 'Confirm a finding');

    expect($section)->toContain('someone other than its finder', 'is dropped from the report');
});

it('points step 5 at the confirmation rule', function () use ($adversarialReviewRulePath): void {
    expect(adversarialReviewRule($adversarialReviewRulePath))->toContain('*Confirm a finding before reporting it*');
});
