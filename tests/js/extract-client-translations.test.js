import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { extractClientTranslateStrings } from "../../scripts/extract-client-translations.mjs";

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
});
