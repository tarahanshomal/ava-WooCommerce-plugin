"""Distribution boundaries must hold even when the checkout contains secrets."""
import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path

spec = importlib.util.spec_from_file_location("package", Path(__file__).resolve().parents[1] / "scripts" / "package.py")
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


class PackageTest(unittest.TestCase):
    def fixture(self, root):
        (root / "ava-woocommerce-plugin.php").write_text("<?php\n/*\nVersion: 1.0.0\n*/\n")
        (root / "readme.txt").write_text("=== Ava ===\n")
        (root / "LICENSE").write_text("GPL-2.0-or-later\n")
        (root / "includes").mkdir()
        (root / "includes" / "class-api.php").write_text("<?php\n")

    def test_reproducible_zip_excludes_development_and_secret_files(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            self.fixture(root)
            (root / ".env").write_text("PRIVATE=do-not-ship")
            (root / "tests").mkdir()
            (root / "tests" / "fixture.php").write_text("<?php // development")
            (root / "includes" / "credentials.env").write_text("PRIVATE=do-not-ship")
            first, second = root / "first.zip", root / "second.zip"
            package.build(first, root)
            package.build(second, root)
            self.assertEqual(first.read_bytes(), second.read_bytes())
            with zipfile.ZipFile(first) as archive:
                self.assertEqual(archive.testzip(), None)
                self.assertEqual(archive.namelist(), ["ava-WooCommerce-plugin/LICENSE", "ava-WooCommerce-plugin/ava-woocommerce-plugin.php", "ava-WooCommerce-plugin/includes/class-api.php", "ava-WooCommerce-plugin/readme.txt"])

    def test_refuses_symlink_in_production_tree(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            self.fixture(root)
            (root / "includes" / "linked.php").symlink_to(root / "ava-woocommerce-plugin.php")
            with self.assertRaises(ValueError):
                package.files(root)

    def test_requires_plugin_header(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            self.fixture(root)
            self.assertEqual(package.version(root), "1.0.0")
            (root / "ava-woocommerce-plugin.php").write_text("<?php\n")
            with self.assertRaises(ValueError):
                package.version(root)


if __name__ == "__main__":
    unittest.main()
