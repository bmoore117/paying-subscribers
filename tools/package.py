#!/usr/bin/env python3
"""Package runtime files only. Preserve the existing SkyWall production plugin basename."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import argparse

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('output', type=Path)
parser.add_argument('--folder', default='paying-subscribers-master', choices=['paying-subscribers-master', 'paying-subscribers'])
args = parser.parse_args()
files = [root / 'paying-subscribers.php', root / 'README.md', *sorted((root / 'includes').glob('*.php'))]
expected = {'stripe.php', 'access.php', 'downloads.php', 'settings.php', 'claim.php'}
if not expected <= {p.name for p in files}:
    parser.error('missing required runtime modules')
args.output.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(args.output, 'w', ZIP_DEFLATED) as archive:
    for src in files:
        archive.write(src, args.folder + '/' + src.relative_to(root).as_posix())
with ZipFile(args.output) as archive:
    assert archive.testzip() is None and len(archive.namelist()) == len(files)
print(f'{args.output}: {len(files)} files; CRC check passed')
