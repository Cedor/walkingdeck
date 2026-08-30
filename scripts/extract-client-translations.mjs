import { mkdir, readdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const ignoredDirectories = new Set([
  ".git",
  ".localDocs",
  "build",
  "node_modules",
  "tests",
  "vendor",
]);
const ignoredFiles = new Set(["_ide_helper.php", "thewalkingdeck.game.php"]);

function skipTrivia(source, start) {
  let index = start;
  while (index < source.length) {
    if (/\s/.test(source[index])) {
      index++;
      continue;
    }
    if (source.startsWith("//", index) || source[index] === "#") {
      const lineEnd = source.indexOf("\n", index + 1);
      index = lineEnd === -1 ? source.length : lineEnd + 1;
      continue;
    }
    if (source.startsWith("/*", index)) {
      const commentEnd = source.indexOf("*/", index + 2);
      index = commentEnd === -1 ? source.length : commentEnd + 2;
      continue;
    }
    break;
  }
  return index;
}

function readQuotedLiteral(source, start, language) {
  const quote = source[start];
  let index = start + 1;
  let value = "";

  while (index < source.length) {
    const character = source[index++];
    if (character === quote) {
      return { value, end: index };
    }
    if (character !== "\\") {
      value += character;
      continue;
    }
    if (index >= source.length) return null;

    const escaped = source[index++];
    if (language === "php" && quote === "'") {
      value += escaped === "'" || escaped === "\\" ? escaped : `\\${escaped}`;
      continue;
    }

    const escapes = {
      "0": "\0",
      b: "\b",
      f: "\f",
      n: "\n",
      r: "\r",
      t: "\t",
      v: "\v",
      "\\": "\\",
      "'": "'",
      '"': '"',
      "`": "`",
      "$": "$",
    };
    value += Object.prototype.hasOwnProperty.call(escapes, escaped)
      ? escapes[escaped]
      : (language === "php" ? `\\${escaped}` : escaped);
  }

  return null;
}

function readJavaScriptTemplate(source, start) {
  const expressions = [];
  let index = start + 1;

  while (index < source.length) {
    if (source[index] === "\\") {
      index += 2;
      continue;
    }
    if (source[index] === "`") {
      return { expressions, end: index + 1 };
    }
    if (!source.startsWith("${", index)) {
      index++;
      continue;
    }

    const expressionStart = index + 2;
    let depth = 1;
    index = expressionStart;
    while (index < source.length && depth > 0) {
      if (source.startsWith("//", index) || source.startsWith("/*", index)) {
        index = skipTrivia(source, index);
        continue;
      }
      if (["'", '"'].includes(source[index])) {
        const literal = readQuotedLiteral(source, index, "js");
        index = literal?.end ?? source.length;
        continue;
      }
      if (source[index] === "`") {
        const template = readJavaScriptTemplate(source, index);
        index = template?.end ?? source.length;
        continue;
      }
      if (source[index] === "{") depth++;
      if (source[index] === "}" && --depth === 0) {
        expressions.push(source.slice(expressionStart, index));
        index++;
        break;
      }
      index++;
    }
  }

  return null;
}

export function extractTranslationStrings(source, language = "php") {
  const strings = [];
  let index = 0;

  while (index < source.length) {
    if (source.startsWith("//", index) || source[index] === "#") {
      index = skipTrivia(source, index);
      continue;
    }
    if (source.startsWith("/*", index)) {
      index = skipTrivia(source, index);
      continue;
    }
    if (language === "js" && source[index] === "`") {
      const template = readJavaScriptTemplate(source, index);
      if (!template) {
        index = source.length;
        continue;
      }
      for (const expression of template.expressions) {
        strings.push(...extractTranslationStrings(expression, "js"));
      }
      index = template.end;
      continue;
    }
    if (["'", '"', "`"].includes(source[index])) {
      const literal = readQuotedLiteral(source, index, language);
      index = literal?.end ?? source.length;
      continue;
    }

    const hasGlobalPrefix = source[index] === "\\"
      && source.startsWith("clienttranslate", index + 1);
    const nameStart = index + (hasGlobalPrefix ? 1 : 0);
    const functionName = source.startsWith("clienttranslate", nameStart)
      ? "clienttranslate"
      : (language === "js" && source[index] === "_" ? "_" : null);
    if (!functionName) {
      index++;
      continue;
    }

    const previous = source[index - 1] || "";
    const nameEnd = nameStart + functionName.length;
    const next = source[nameEnd] || "";
    if (
      /[A-Za-z0-9_$\.]/.test(previous)
      || /[A-Za-z0-9_$]/.test(next)
    ) {
      index++;
      continue;
    }

    let argumentStart = skipTrivia(source, nameEnd);
    if (source[argumentStart] !== "(") {
      index = nameEnd;
      continue;
    }
    argumentStart = skipTrivia(source, argumentStart + 1);
    if (!["'", '"', "`"].includes(source[argumentStart])) {
      index = argumentStart;
      continue;
    }

    const literal = readQuotedLiteral(source, argumentStart, language);
    if (literal) {
      strings.push(literal.value);
      index = literal.end;
    } else {
      index = source.length;
    }
  }

  return strings;
}

export function extractClientTranslateStrings(source, language = "php") {
  return extractTranslationStrings(source, language);
}

async function findSourceFiles(directory) {
  const files = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (!ignoredDirectories.has(entry.name)) {
        files.push(...await findSourceFiles(path.join(directory, entry.name)));
      }
      continue;
    }
    if (
      entry.isFile()
      && !ignoredFiles.has(entry.name)
      && !/\.(?:test|spec)\.(?:php|[cm]?js)$/i.test(entry.name)
      && [".php", ".js", ".mjs", ".cjs"].includes(
        path.extname(entry.name).toLowerCase()
      )
    ) {
      files.push(path.join(directory, entry.name));
    }
  }
  return files;
}

async function main() {
  const outputPath = path.resolve(
    projectRoot,
    process.argv[2] || "build/clienttranslate-strings.txt"
  );
  const translations = new Set();

  for (const file of await findSourceFiles(projectRoot)) {
    const source = await readFile(file, "utf8");
    const language = path.extname(file).toLowerCase() === ".php" ? "php" : "js";
    for (const translation of extractTranslationStrings(source, language)) {
      translations.add(translation.replaceAll("\r", "\\r").replaceAll("\n", "\\n"));
    }
  }

  const sortedTranslations = [...translations].sort((left, right) => (
    left.localeCompare(right, "en", { sensitivity: "base" })
      || left.localeCompare(right, "en", { sensitivity: "variant" })
  ));
  await mkdir(path.dirname(outputPath), { recursive: true });
  await writeFile(
    outputPath,
    sortedTranslations.length > 0 ? `${sortedTranslations.join("\n")}\n` : "",
    "utf8"
  );
  console.log(
    `${sortedTranslations.length} translations written to ${path.relative(projectRoot, outputPath)}`
  );
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  await main();
}
