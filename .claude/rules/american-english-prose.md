<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/rules/american-english-prose.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): core.
-->
<!-- cspell:ignore ISCED ISCO oneline -->
# Rule — American English for prose, and a dictionary entry that overrides it needs a stated reason

First-party prose in this repository is written in **American English**: comments, docblocks, documentation, test names, template copy and field labels, commit messages, issue and pull-request bodies. The arbiter is **Merriam-Webster's first-listed spelling**: `analyze`, `behavior`, `color`, `normalize`, `honored`.

The convention is already enforced. [`cspell.json`](../../cspell.json) sets `"language": "en"`, which is US English, so the base dictionary rejects British forms wherever the `spell` job reaches, and until it was written down here, it was enforced without being stated anywhere, so it was discoverable only by tripping that job.

**There is exactly one way to defeat it, and half of this rule is about that way.** An entry in [`project-words.txt`](../../project-words.txt) silences a word **repo-wide, permanently, and silently**. Nothing reports it afterwards: the word simply passes, so no run has anything to say about it. **An entry that overrides the convention needs a stated reason, recorded where a later reader can find it.** Without one, the dictionary silently becomes the convention.

## Why this is a standing order

**Because each addition is individually defensible and the aggregate is not.** A word is added because it is genuinely needed at one site, and thereafter the gate cannot enforce the convention for that word *anywhere*, including in prose nobody has written yet. The cost is paid by a later reader, not by the author. Repeated, it replaces the convention with its opposite: `wordpress-importer`'s dictionary had absorbed ten British spellings before the convention was written down, and `uams-statamic`'s review of four entries (UAMS-Web/uams-statamic-archive#2318) found two that had been absorbed for reasons that looked like the carve-out and were not.

**And the entry carries no trace of its reasoning.** A long sorted word list makes every line look alike: a proper noun, a package name, a British spelling somebody allowed once, and a genuine misspelling are indistinguishable. Reviewing one means reconstructing why it was added.

**Ask the gate, not `project-words.txt`.** The effective vocabulary is the project dictionary *plus* cspell's built-in US dictionary, so grepping the project file answers a narrower question than the one that matters. `analyses` is absent from every project dictionary and passes anyway, being ordinary American English, a word an earlier version of this rule listed as rejected until the gate was actually run against it.

**And a dictionary entry is a literal string rather than a lemma**, so it passes forever and silently while its *inflected forms still fail*. A contributor who watches the plural pass reasonably concludes the word is known, writes the singular in the next sentence, and is rejected for a reason nothing on screen explains.

## The one word where asking the gate cannot answer

**And it is the word the advice above is demonstrated with**, which is why this belongs here rather than in a footnote. `analyses` is simultaneously the correct American plural of `analysis` and the British third-person form of the verb whose American spelling is `analyzes`. cspell matches strings and has no view of which sense a sentence meant, so it accepts both: *the runner analyses the export* passes **permanently**, no `project-words.txt` entry is involved, and none could remove it.

**The class is wider than one word, and it is nameable.** Take any British verb of that shape, whose American spelling ends in `-yze`. Its third-person form is spelled identically to the plural of the matching noun, because `analysis` pluralizes to `analyses`, so the collision is structural rather than a coincidence of one word. Measured in `wordpress-importer`, all six pass the gate as bare strings:

`analyses` · `paralyses` · `catalyses` · `dialyses` · `electrolyses` · `hydrolyses`

**So the advice above carries a bound, and this is the only place in this rule where it does not hold.** For any of the six words above the gate returns clean whichever sense was written, and the reader has to decide from the sentence rather than from the checker. Asking the gate is still right for every other word; it simply cannot arbitrate this one.

**The British forms themselves are deliberately not spelled out anywhere in this file, and the reason is the rule demonstrating itself.** Writing one here (in prose *or* inside a fenced block, which cspell also checks) adds a hit to this very document in every repository whose dictionary does not carry it. **Documentation about a forbidden spelling is subject to the rule it documents**, so name such a word by reference rather than by spelling it.

## Two categories of legitimate override

A British spelling that stands in a first-party file is not automatically wrong, and neither is a dictionary entry that admits one. Exactly two reasons qualify, and they are narrower than "standardized data". Both need the reason written down:

1. **A name that cannot be reworded**: tool vocabulary a gate itself runs, or a command, a subcommand, a package, an API field, a config key, a place name. Removing or respelling it breaks a *command* or misnames a *thing* rather than changing prose. PHPStan's own subcommand is spelled the British way, and where a repository's gate invokes it by that spelling it appears as the literal argument the `phpstan` job runs; removing the entry would make the `spell` job reject the command the `phpstan` job runs.
2. **Generated or verbatim-quoted external data**: registry names, standard labels, official body names reproduced exactly (schema.org, the ISO 6523 ICD registry, ILO ISCO-08, UNESCO ISCED-F). Americanizing one corrupts data that must mirror an external vocabulary, breaks anything keyed on the official label, and is reintroduced by the next generator run regardless. **Prefer a path exclusion, and check whether one already exists**, before adding a word.

**The second category is recorded even where it does not currently bite**, deliberately. With only category 1 written down, a future sweep reads a clean prose corpus, finds an entry that guards no prose, and deletes something that only ever guarded data.

## How to apply

1. **Fix it in source; allow-listing is the last resort.** When the spell check flags a word, decide rather than reflexively adding it. A genuine misspelling gets corrected. A British spelling in first-party prose gets changed to the American form. A real misspelling allow-listed once stays wrong forever, and every stale entry weakens the check for everybody. Where a spelling is genuinely ambiguous, check against the gate rather than against habit.

2. **Before adding a word, ask which of the two categories it is.** If it is neither, the word is a British spelling and the prose is what to change.

3. **Reach for a path exclusion or a file-scoped override before a dictionary entry, because they differ in scope and in visibility.** A path exclusion is scoped to the files that need it and is visible in [`cspell.json`](../../cspell.json); so is an entry in its `overrides`, keyed by filename, which is the right home for a word quoted as an *example* rather than used as vocabulary; it keeps the exception out of every other file. A dictionary entry is global and invisible. **`cspell.json` is JSON and cannot carry a comment, so the reason for an override goes in the prose of the file the override names.**

   The worked example is the British spelling of `defense`, measured in `uams-statamic`, and it is worth knowing because the argument for keeping it sounded exactly right. It was defended as protecting standardized data (schema.org, the ISO 6523 ICD registry, ILO ISCO-08, UNESCO ISCED-F). Those occurrences are real. **The question is whether the dictionary entry is what protects them, and it is not.** Measured by removing the entry and running the gate:

   ```
   scripts/local-ci.browser.test.mjs:28:12 - Unknown word (...)
   CSpell: Files checked: 2662, Issues found: 1 in 1 file.
   ```

   One hit, from a script, and **none from the standardized data at all**, so the entry guarded nothing while silencing the word across the whole tree. That is the shape to watch for: an entry justified by a category that something else already covers.

   **Two different mechanisms already covered it, and conflating them is how the entry survived review.** Counted directly in that repository:

   ```
   app/Enums/                            9 occurrences   covered by ignorePaths in cspell.json
   app/Services/EnumGeneration/Schema/   3 occurrences   .txt and .jsonld -- NOT SCANNED AT ALL
   scripts/                              1 occurrence    the one real hit
   ```

   Only the first is a path exclusion. The second is invisible to the gate because that repository's cspell scans `**/*.{php,js,mjs,vue,md,yml,yaml}` and `**/*.antlers.html`, and those two extensions are not in it; a file type is not a policy, and adding one of those extensions later would surface those occurrences with nothing having changed about the data. **So when checking whether an exclusion already covers a word, establish which mechanism is doing the work.** "It is already handled" is three different situations, and only one of them is durable.

4. **Record the reason where this repository's dictionary convention puts it, so that it is retrievable.** The default is the pull request that adds the entry: state the reason in the PR body, in the same change that adds the word. A later reader finds it with a content search over the file's history:

   ```bash
   git log -S'<word>' --oneline -- project-words.txt
   ```

   That returns the commit that introduced the word, whose merge commit links the pull request. A word that is not in the file returns nothing, so an empty result means the word was never added rather than that the search is broken (verified in `uams-statamic` on a place-name entry, which resolves to the commit that adopted cspell).

5. **Keep the file sorted.** The word list below the header is sorted by code point on the lowercased word. Add a word in its sorted position and leave the header comments at the top.

6. **`project-words.txt` is read by the `spell` job on every branch**, so an edit to it is felt by every open pull request. Say what you changed when handing over.

## What this does not cover

- **Third-party or vendored prose.** The convention governs what this repository writes. A dependency's own spelling is its business, and where its files reach a spell check the answer is a path exclusion, not a dictionary entry.
- **Quoted external text.** A verbatim quotation keeps its original spelling, exactly as [`impersonal-voice-in-github-artifacts`](impersonal-voice-in-github-artifacts.md) keeps a quotation's original pronouns. Prefer scoping the file over admitting the word globally.
- **Identifiers that are not free text.** A symbol whose spelling is fixed by something outside this repository (a vendor API field, a config key, a CLI subcommand) is a name, not prose, and falls under the first qualifying reason above rather than being a violation to fix.

## The DRY line

This file is the standing statement of **which English this repository writes, and what justifies an exception**. The pull-request-time workflow (when to run the spell check, and reconciling each flagged word before opening) stays in [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md), which points here rather than restating the convention. It composes with [`no-emoji-in-durable-records`](no-emoji-in-durable-records.md) and [`impersonal-voice-in-github-artifacts`](impersonal-voice-in-github-artifacts.md), which constrain the same prose along different axes; how a rule or skill is worded belongs to the writing skills, and the `cspell` mechanics belong to that tool.

Which files the `spell` job actually reads belongs to the [`local-ci`](../skills/local-ci/SKILL.md) skill and to the runner.

