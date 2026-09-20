AGENTS.md — Quick guide for AI coding agents

Checklist for getting productive
- Understand repo purpose: this is a data-extraction + HTML generator for game asset packages (.ipf/.ies -> XML -> HTML).
- Run the canonical data pipeline (extract IPF -> convert IES -> produce XML -> generate HTML).
- Know where to look: key scripts and binaries live under `tools/`; archives live under `ge/`.

Essential commands (PowerShell, run from project root)
```powershell
# enter tools and run the automated extraction script (recommended)
cd .\tools
.\Prepare.bat        # invokes tools\php\php.exe Prepare.php
.
# generate English HTML (interactive)
.\Parse(English).bat # invokes tools\php\php.exe English.php
```

Why these steps matter
- `tools/Prepare.php` runs the bundled native extractors (`iz.exe`, `ix3.exe`, `ez.exe`) to unpack `.ipf` and produce `.ies`/`.xml` artifacts and image files under `tools/Images/` and `tools/xml/`.
- `tools/English.php` (interactive) parses datatable XMLs (using `function.xmls2Arrays.php` -> `SaXtA`) and writes `index.html` (in the project root).

Files & locations agents should inspect first
- `tools/Prepare.php` — the extraction sequence (calls: `iz.exe`, `ix3.exe`, `ez.exe`) and where outputs are copied
- `tools/English.php` — the HTML generator and its interactive prompts; uses `SaXtA` from `tools/Function.xmls2Arrays.php`
- `tools/Function.xmls2Arrays.php` — key parser that turns datatable XML attributes into PHP arrays: ${ArrayName}[Attribute][ClassID]
- `tools/EnglishTemplate` — HTML template that `English.php` fills; useful when changing output structure.
- `ge/` — contains the original `.ipf` packages (source archives)

Project-specific conventions & patterns
- Heavy use of globals in PHP: functions populate global arrays (e.g., `SaXtA("...", 'Skill')` => `$Skill[...]`). Agents should trace symbols via these globals.
- Naming convention: datatables named `datatable_*.xml` and referenced directly by `SaXtA` calls in `English.php` (search these strings to discover new data sources).
- Image copy rules: `English.php` copies UI bitmaps (e.g. `./ui/illust/*.bmp`) into `tools/Images/` — expect images to be created or moved manually for some entries (see comments in code).
- Interactive toggles: `English.php` prompts for which categories to parse (Weapon/Armor/Accs/Achievements). Automating it requires supplying stdin responses or editing the script.

Integration & dependency notes
- Bundled dependencies: Windows executables live in `tools/` (ez.exe, ix3.exe, iz.exe) and a PHP runtime in `tools/PHP/`. The pipeline expects a Windows environment.
- Working directory matters: many scripts call executables and files with relative paths. Best practice: run the commands from `tools/` so executables and templates resolve.
- Antivirus / execution policy: native EXEs and bundled PHP may be blocked — if extraction silently fails, check Windows Defender/AppLocker and run from an elevated shell.

Quick debugging checklist
- If `Prepare.php` fails to find `.ipf` files: ensure the `.ipf` files from `ge/` are accessible from `tools/` or update `Prepare.php` paths (e.g., `../ge/dictionary.ipf`).
- If `English.php` produces no output: ensure `tools/xml/` contains `datatable_*.xml` files and that `function.xmls2Arrays.php` is loaded.
- To automate the interactive choices in `English.php` either pipe input into PHP or set the script to skip prompts (modify lines around `fopen("php://stdin")`).

Search tips for agents (fast entry points)
- grep for `SaXtA(` to see which datatables are used
- inspect `EnglishTemplate` to understand the final HTML placeholders like `[[Weapon_List]]`
- look for `shell_exec('iz.exe` and similar in `Prepare.php` to find the extraction order

If you need to modify the pipeline
- Edit `tools/Prepare.php` to point to absolute paths for `.ipf` files or to run from project root; keep changes minimal and document them in git.
- To non-interactively generate the HTML, add a small wrapper that echoes the expected answers into `php English.php`.

-- end of AGENTS.md

