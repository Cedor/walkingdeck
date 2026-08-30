import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
  extractClientTranslateStrings,
  extractTranslationStrings,
} from "../../scripts/extract-client-translations.mjs";

describe("clienttranslate extraction", () => {
  it("extracts direct PHP string arguments and ignores comments and duplicates", () => {
    const source = String.raw`
      // \clienttranslate('Commented out')
      \clienttranslate('Second');
      clienttranslate(
        "First"
      );
      \clienttranslate('Second');
      $message = 'clienttranslate("Inside a string")';
    `;

    assert.deepEqual(
      [...new Set(extractClientTranslateStrings(source, "php"))].sort(),
      ["First", "Second"]
    );
  });

  it("supports escaped characters in PHP and JavaScript literals", () => {
    assert.deepEqual(
      extractClientTranslateStrings(String.raw`\clienttranslate('It\'s ready')`, "php"),
      ["It's ready"]
    );
    assert.deepEqual(
      extractClientTranslateStrings(String.raw`clienttranslate("Line\nnext")`, "js"),
      ["Line\nnext"]
    );
  });

  it("extracts JavaScript _() calls without matching tests or member methods", () => {
    const source = String.raw`
      // _("Commented out")
      _("Second");
      _(
        'First'
      );
      _("Second");
      clienttranslate("Client marker");
      object._("Member method");
      const example = '_("Inside a string")';
    ` + '\nconst html = `<b>${_("My hand")}</b>`;' +
      '\nconst rawTemplate = `<b>_("Inside template text")</b>`;';

    assert.deepEqual(
      [...new Set(extractTranslationStrings(source, "js"))].sort(),
      ["Client marker", "First", "My hand", "Second"]
    );
    assert.deepEqual(extractTranslationStrings('_("PHP helper")', "php"), []);
  });
});
