import { readFile } from "node:fs/promises";

const files = ["gameoptions.json", "gamepreferences.json", "stats.json"];

for (const file of files) {
  JSON.parse(await readFile(new URL(`../${file}`, import.meta.url), "utf8"));
  console.log(`${file}: valid JSON`);
}
