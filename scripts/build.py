#!/usr/bin/env python3
"""Create a reproducible, uploadable WordPress plugin ZIP using the standard library."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import re

root = Path(__file__).resolve().parent.parent
version = re.search(r"Version:\s*([\d.]+)", (root / "gz-ai-discoverability.php").read_text()).group(1)
output = root / "dist" / f"gz-ai-discoverability-{version}.zip"
output.parent.mkdir(exist_ok=True)
files = [root / name for name in ["gz-ai-discoverability.php", "uninstall.php", "README.md", "readme.txt", "LICENSE"]]
for directory in ["includes", "assets"]:
    files.extend(path for path in (root / directory).rglob("*") if path.is_file())
with ZipFile(output, "w", compression=ZIP_DEFLATED) as archive:
    for path in sorted(files):
        info = ZipInfo("gz-ai-discoverability/" + path.relative_to(root).as_posix(), (2026, 10, 20, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, path.read_bytes())
print(output)
