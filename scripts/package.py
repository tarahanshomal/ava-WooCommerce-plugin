#!/usr/bin/env python3
"""Build a deterministic, installable WordPress ZIP from an explicit allowlist."""
from __future__ import annotations

import argparse
import hashlib
import re
import stat
import zipfile
from pathlib import Path

SLUG = "ava-WooCommerce-plugin"
ROOT = Path(__file__).resolve().parents[1]
TOP_LEVEL = ("ava-woocommerce-plugin.php", "uninstall.php", "index.php", "readme.txt", "README.md", "LICENSE", "CHANGELOG.md")
PRODUCTION_DIRS = ("includes", "assets", "languages")
ALLOWED_SUFFIXES = {".php", ".css", ".js", ".json", ".svg", ".png", ".jpg", ".webp", ".pot", ".po", ".mo"}
FIXED_TIMESTAMP = (2026, 11, 4, 0, 0, 0)


def version(root: Path = ROOT) -> str:
    main = (root / "ava-woocommerce-plugin.php").read_text(encoding="utf-8")
    match = re.search(r"^\s*\*?\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?)\s*$", main, re.MULTILINE)
    if not match:
        raise ValueError("Main plugin file must declare a semantic Version header")
    return match.group(1)


def files(root: Path = ROOT) -> list[Path]:
    selected = []
    for name in TOP_LEVEL:
        path = root / name
        if path.exists():
            selected.append(path)
    for name in PRODUCTION_DIRS:
        directory = root / name
        if directory.exists():
            selected.extend(path for path in directory.rglob("*") if path.is_file() and path.suffix.lower() in ALLOWED_SUFFIXES)
    for required in ("ava-woocommerce-plugin.php", "readme.txt", "LICENSE"):
        if root / required not in selected:
            raise ValueError("Missing required distribution file: " + required)
    for path in selected:
        if path.is_symlink() or any(part.startswith(".") for part in path.relative_to(root).parts):
            raise ValueError("Refusing hidden or symlink distribution file: " + str(path))
        if not path.resolve().is_relative_to(root.resolve()):
            raise ValueError("Distribution file escapes source root")
    return sorted(selected, key=lambda path: path.relative_to(root).as_posix())


def build(output: Path, root: Path = ROOT) -> Path:
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files(root):
            name = SLUG + "/" + path.relative_to(root).as_posix()
            info = zipfile.ZipInfo(name, FIXED_TIMESTAMP)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return output


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--tag", help="Require the Git tag to match the plugin Version header")
    args = parser.parse_args()
    release_version = version()
    if args.tag and args.tag != "v" + release_version:
        parser.error("Tag must be v" + release_version)
    output = args.output or ROOT / "build" / (SLUG + "-" + release_version + ".zip")
    build(output)
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix(output.suffix + ".sha256").write_text(digest + "  " + output.name + "\n", encoding="ascii")
    print(str(output))
    print("SHA256: " + digest)


if __name__ == "__main__":
    main()
